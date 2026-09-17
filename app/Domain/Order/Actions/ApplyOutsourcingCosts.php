<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Support\Money;

/**
 * Turns each وسيط line's snapshotted unit cost into what the line actually cost.
 *
 * **The وسيط twin of {@see ApplyManufacturingRates}**, called from the same hook in
 * {@see ChangeOrderStatus} — the first time an order reaches «جاهزة» — and for the same reason:
 * a cost is recognised when the goods exist. Before that the order has a *price agreed with a
 * vendor* and no goods, and an order written off on the vendor's bench never cost anything.
 *
 * **`unit_cost` is read off the line, never off the catalogue.** It was copied there when the
 * order was taken — see {@see AddOrderItem} — which is what makes a vendor's price rise leave
 * every earlier order alone. This action never looks a product up, and that is the point.
 *
 * **Multiplied by what is actually being charged for**, not by what was ordered:
 * {@see OrderItem::billableQuantity()} is the same quantity `line_total` is built on, so a line
 * that came up short is costed and priced on one number rather than two.
 *
 * A line with no `unit_cost` is left alone — that is every line of every order we make ourselves,
 * whose cost comes off the shelf and out of the rate table instead.
 */
final class ApplyOutsourcingCosts
{
    public function __construct(
        private readonly RecalculateOrderItemCost $recalculateItemCost,
    ) {}

    public function __invoke(Order $order): void
    {
        foreach ($order->items as $item) {
            if ($item->unit_cost === null) {
                continue;
            }

            $item->forceFill([
                // At six places before rounding once, exactly as `deriveLineTotal()` multiplies
                // the price out — the two numbers are set against each other, so they cannot
                // round differently.
                'outsourcing_cost' => Money::round(
                    bcmul((string) $item->unit_cost, $item->billableQuantity(), 6),
                ),
            ])->save();

            // The line's own total, restated now that a fourth component exists on it — the same
            // call `DeductOrderStock` makes the moment it knows a material cost.
            ($this->recalculateItemCost)($item);
        }
    }
}
