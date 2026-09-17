<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries;

use App\Domain\Order\Models\Order;

/**
 * How many orders each of these customers has placed.
 *
 * **The reason this lives here and not on the customer.** `Order` already knows `Customer` —
 * that is the direction the foreign key runs — and an `orders()` relation on `Customer` would
 * close the loop and make the two domains a cycle. So the count is asked of the orders, keyed by
 * customer, and the controller — which is allowed to know both — is what puts the two together.
 *
 * **One query for a whole page, not one per row.** It takes the ids the page already has rather
 * than a customer at a time; a list of fifteen customers costs exactly one extra query whatever
 * else the page is doing.
 *
 * Soft-deleted orders are excluded, since {@see Order} soft deletes and the default scope is
 * what every other count in this system means by "orders".
 */
final class OrderCountsByCustomerQuery
{
    /**
     * @param  list<int>  $customerIds
     * @return array<int, int> customer id → how many orders, **including a zero for every id
     *                         asked about**. A missing key would leave the caller choosing
     *                         between a blank and a nought, and those read differently.
     */
    public function __invoke(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $tallied = Order::query()
            ->whereIn('customer_id', $customerIds)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, count(*) as aggregate')
            ->pluck('aggregate', 'customer_id');

        $counts = [];
        foreach ($customerIds as $id) {
            $counts[$id] = (int) ($tallied[$id] ?? 0);
        }

        return $counts;
    }
}
