<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Support\Money;

/**
 * Adds every line's `cogs` up and writes the answer onto the order — the cost-side twin of
 * {@see RecalculateOrderTotals}.
 *
 * **The one place `orders.total_cogs` is ever written.** Null until at least one line has a
 * `cogs` of its own; a mixed order is not expected in the ordinary flow — `DeductOrderStock` and
 * `ApplyManufacturingRates` both process every line together, in the same transaction, the
 * moment an order enters ready — but summing whatever *is* known rather than refusing the
 * whole total is the more useful answer if that invariant is ever bent.
 *
 * Gross profit itself is never stored: it is `grand_total - total_cogs`, computed at report time
 * from two already-cached columns rather than a third cache to keep in sync.
 */
final class RecalculateOrderCogs
{
    public function __invoke(Order $order): Order
    {
        $known = $order->items()->get()
            ->map(fn (OrderItem $item) => $item->cogs)
            ->filter(fn (?string $cogs) => $cogs !== null)
            ->map(fn (string $cogs) => (string) $cogs)
            ->all();

        $order->forceFill(['total_cogs' => $known === [] ? null : Money::sum(...$known)])->save();

        return $order;
    }
}
