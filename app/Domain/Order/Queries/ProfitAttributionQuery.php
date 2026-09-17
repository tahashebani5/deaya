<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries;

use App\Domain\Order\Models\Order;

/**
 * Everything another context needs to split one order's profit, as plain arrays.
 *
 * The seam that lets Investment attribute a sale without ever loading an `Order` — RULES §3: a
 * context reaches another through its Service, and what comes back is data rather than models.
 *
 * **The line's fulfilment movement is the join key, never `stock_movements.reference_id`.** That
 * column has no foreign key, carries `stock_arrivals.id` for an arrival, and is freely settable
 * through `POST /stock-movements/fulfillments` — so a query keyed on it would sweep in rows that
 * belong to no order line at all. `order_items.fulfillment_stock_movement_id` is written by
 * exactly two actions and is rewritten when a line is restated, which is precisely the pointer a
 * profit split must follow.
 */
final class ProfitAttributionQuery
{
    /**
     * @return array{
     *     order_id: int,
     *     code: string,
     *     status: string,
     *     grand_total: string,
     *     items_total: string,
     *     total_cogs: ?string,
     *     gross_profit: ?string,
     *     lines: list<array{
     *         line_id: int,
     *         movement_id: int,
     *         stock_purchased: bool,
     *         line_total: string,
     *         conversion_cost: string
     *     }>
     * }|null
     */
    public function __invoke(int $orderId): ?array
    {
        return $this->many([$orderId])[$orderId] ?? null;
    }

    /**
     * The same, for a page of orders in one query.
     *
     * What a report reads. One order at a time is two queries apiece, and a screen listing the
     * fifteen orders a deal sold into would spend thirty of them saying what two can.
     *
     * @param  list<int>  $orderIds
     * @return array<int, array{
     *     order_id: int,
     *     code: string,
     *     status: string,
     *     grand_total: string,
     *     items_total: string,
     *     total_cogs: ?string,
     *     gross_profit: ?string,
     *     lines: list<array{
     *         line_id: int,
     *         movement_id: int,
     *         stock_purchased: bool,
     *         line_total: string,
     *         conversion_cost: string
     *     }>
     * }>  keyed by order id, missing whatever was not found
     */
    public function many(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $attributions = [];

        foreach (Order::query()->with('items')->whereKey($orderIds)->get() as $order) {
            $attributions[(int) $order->getKey()] = $this->attribute($order);
        }

        return $attributions;
    }

    /**
     * @return array<string, mixed>
     */
    private function attribute(Order $order): array
    {
        $lines = [];

        foreach ($order->items as $item) {
            if ($item->fulfillment_stock_movement_id === null) {
                continue;
            }

            $lines[] = [
                'line_id' => (int) $item->getKey(),
                'movement_id' => (int) $item->fulfillment_stock_movement_id,
                // Whether the press already bought this line's plain material off the shelf. A
                // line that did has settled with whoever financed it, so the delivery must not
                // pay him a second time — see OrderDealSlices, which drops those draws from the
                // deal's slice while still counting them in the allocation's denominator.
                'stock_purchased' => $item->stock_purchased_at !== null,
                'line_total' => (string) ($item->line_total ?? '0.00'),
                // What the company added to the goods on this line. Kept separate from material
                // so a deal is never charged for it twice and never credited with it.
                'conversion_cost' => bcadd(
                    bcadd((string) ($item->labor_cost ?? '0.00'), (string) ($item->overhead_cost ?? '0.00'), 2),
                    (string) ($item->outsourcing_cost ?? '0.00'),
                    2,
                ),
            ];
        }

        return [
            'order_id' => (int) $order->getKey(),
            'code' => (string) $order->id,
            'status' => $order->status->value,
            'grand_total' => (string) $order->grand_total,
            'items_total' => (string) $order->items_total,
            'total_cogs' => $order->total_cogs === null ? null : (string) $order->total_cogs,
            'gross_profit' => $order->grossProfit(),
            'lines' => $lines,
        ];
    }
}
