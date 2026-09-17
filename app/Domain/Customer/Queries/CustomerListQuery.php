<?php

declare(strict_types=1);

namespace App\Domain\Customer\Queries;

use App\Domain\Customer\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The customer index: search, activity filter, and the order to read it in.
 *
 * Lives in its own class rather than in the controller so the same list can be reused by an
 * admin panel or an export without duplicating the query.
 *
 * Two of the things it can be asked are about *orders* — who has never placed one, and who has
 * not placed one for longest. Neither is answered by walking a relation from here: see
 * {@see CustomerOrderActivity} for why this module holds a port instead, and what the Order
 * module puts behind it.
 */
final class CustomerListQuery
{
    public function __construct(private readonly CustomerOrderActivity $activity) {}

    /**
     * @return LengthAwarePaginator<int, Customer>
     */
    public function __invoke(CustomerFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return Customer::query()
            ->select('customers.*')
            // The date rides along **only when the list is sorted by it**, which is also the
            // only time anything shows it. An ordinary page pays for no subquery, and no reader
            // is sent a fact about the orders on a request that never asked about them —
            // `orders.view` is checked before this filter is allowed at all.
            ->when(
                $filters->sort === CustomerSort::LeastRecentOrder,
                fn (Builder $query) => $query->addSelect([
                    'last_order_at' => $this->activity->lastOrderedAt(),
                ]),
            )
            // Eager-loaded: the resource renders shops for every row, and each shop names its
            // trade — one query per customer otherwise, and one per shop after that.
            ->with(Customer::SHOP_RELATIONS)
            ->when($filters->search !== null, function (Builder $query) use ($filters) {
                $term = '%'.$filters->search.'%';

                // Grouped so the OR set cannot escape and swallow the is_active filter.
                $query->where(function (Builder $query) use ($term) {
                    $query->where('name', 'ilike', $term)
                        ->orWhere('code', 'ilike', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when($filters->isActive !== null, fn (Builder $query) => $query->where('is_active', $filters->isActive))
            ->when($filters->hasOrders !== null, fn (Builder $query) => $filters->hasOrders
                ? $query->whereExists($this->activity->everOrdered())
                : $query->whereNotExists($this->activity->everOrdered()))
            ->tap(fn (Builder $query) => $this->order($query, $filters->sort))
            ->paginate($perPage);
    }

    /**
     * @param  Builder<Customer>  $query
     */
    private function order(Builder $query, CustomerSort $sort): void
    {
        if ($sort === CustomerSort::LeastRecentOrder) {
            // `nulls last` is the decision, not a database detail: a customer who has never
            // ordered has no silence to measure, and «بدون طلبات» is a filter of its own. By
            // the alias, which Postgres allows in `order by` — repeating the subquery here
            // would be a second copy of it to keep in step.
            $query->orderByRaw('last_order_at asc nulls last');
        }

        // Always the tiebreak, and the whole order for `newest`: two customers who last ordered
        // on the same day still have to come back in a stable order, or page two repeats a row
        // from page one.
        $query->orderByDesc('customers.id');
    }
}
