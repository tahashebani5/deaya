<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries;

use App\Domain\Customer\Queries\CustomerOrderActivity;
use App\Domain\Order\Models\Order;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Answers the customer list's two questions about orders, from inside the orders.
 *
 * The implementation of {@see CustomerOrderActivity}, and the only place that knows both the
 * `orders` table and the fact that a customer list wants to be sorted by it.
 *
 * **`toBase()`, not `getQuery()`.** The first applies the model's global scopes and the second
 * drops them — and the scope in question is soft deletes, so the difference is whether a
 * deleted order still counts as one. It does not, here as in
 * {@see OrderCountsByCustomerQuery} and everywhere else in this system.
 */
final class OrderCustomerActivity implements CustomerOrderActivity
{
    public function lastOrderedAt(): Builder
    {
        return Order::query()
            // `placed_at` is when the customer ordered; `created_at` is when the row was
            // written. They are the same instant for every order this app creates — see
            // {@see OrderTotalsQuery} — and the fallback is for the rows that might one day
            // arrive from an import without one, which would otherwise read as «never ordered»
            // while `everOrdered()` says the opposite.
            ->selectRaw('max(coalesce(orders.placed_at, orders.created_at))')
            ->whereColumn('orders.customer_id', 'customers.id')
            ->toBase();
    }

    public function everOrdered(): Builder
    {
        return Order::query()
            // A constant: `exists` cares that a row comes back, never what is in it.
            ->select(DB::raw('1'))
            ->whereColumn('orders.customer_id', 'customers.id')
            ->toBase();
    }
}
