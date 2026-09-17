<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries;

use App\Domain\Order\Actions\DeductOrderStock;
use App\Domain\Order\Models\OrderItem;

/**
 * The lines of one order that **bought** their material off the shelf, and the draw each did it
 * with — everything Investment needs to pay whoever sold it, as plain arrays.
 *
 * The seam beside {@see ProfitAttributionQuery}, and deliberately a second one rather than more
 * fields on that: the two answer for the two roads a deal can be on, and a caller on one road has
 * no business reading the other's figures. This one carries no money at all — the price is on the
 * cost layers, which is Inventory's to hand over.
 *
 * **`stock_purchased_at` is the whole condition.** It is stamped by
 * {@see DeductOrderStock} exactly when a line that the press runs drew
 * on a layer whose deal sells to the press, so nothing here re-derives what a line *is* — the
 * question was answered on the day the stock left, and re-asking it would give a different answer
 * the day somebody re-files a product category.
 *
 * **The line id is what a payment is keyed on, not the movement.** A restatement replaces the
 * movement and keeps the line, so keying on the line is what lets the correction reverse the
 * first figure and post the second rather than leaving two payments standing for one draw.
 */
final class StockPurchaseAttributionQuery
{
    /**
     * @return list<array{line_id: int, movement_id: int}>
     */
    public function __invoke(int $orderId): array
    {
        return OrderItem::query()
            ->where('order_id', $orderId)
            ->whereNotNull('stock_purchased_at')
            ->whereNotNull('fulfillment_stock_movement_id')
            ->orderBy('id')
            ->get(['id', 'fulfillment_stock_movement_id'])
            ->map(fn (OrderItem $item): array => [
                'line_id' => (int) $item->getKey(),
                'movement_id' => (int) $item->fulfillment_stock_movement_id,
            ])
            ->all();
    }
}
