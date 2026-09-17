<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Actions;

use App\Domain\Order\Actions\RecalculateOrderTotals;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\PurchaseOrder\Models\PurchaseOrderItem;
use App\Domain\PurchaseOrder\Support\Money;

/**
 * Derives `total_amount` and `total_additional_cost` from an order's own lines and additional
 * costs.
 *
 * The one place that decides what a purchase order is planned to cost, so a client can never post
 * a total and no two code paths can disagree about how one is reached — the same role
 * {@see RecalculateOrderTotals} plays for orders. Called after
 * {@see AllocatePurchaseOrderAdditionalCosts}, whenever anything could move a line's cost or the
 * order's additional costs: creating the order, replacing its lines or its additional costs.
 *
 * `total_amount` sums each line's `final_total_cost` — base cost plus its allocated share — so it
 * is already the order's grand total, inclusive of additional costs; `total_additional_cost`
 * exists so that split stays visible rather than folded away.
 *
 * A line with no `final_total_cost` contributes nothing to `total_amount` rather than failing the
 * sum — cost is optional history on rows written before this feature existed, not a reason a new
 * save should be refused.
 */
final class RecalculatePurchaseOrderTotal
{
    public function __invoke(PurchaseOrder $order): PurchaseOrder
    {
        $lineTotals = $order->items()->get()
            ->map(fn (PurchaseOrderItem $item) => $item->final_total_cost === null ? null : (string) $item->final_total_cost)
            ->filter(fn (?string $total) => $total !== null)
            ->all();

        $additionalCostAmounts = $order->additionalCosts()->get()
            ->map(fn ($cost) => (string) $cost->amount)
            ->all();

        $order->forceFill([
            'total_amount' => $lineTotals === [] ? null : Money::sum(...$lineTotals),
            'total_additional_cost' => $additionalCostAmounts === [] ? '0.00' : Money::sum(...$additionalCostAmounts),
        ])->save();

        return $order;
    }
}
