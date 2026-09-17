<?php

declare(strict_types=1);

namespace App\Domain\Investor\Queries;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Investor\Support\Money;
use App\Domain\Order\Enums\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * What a deal's goods are doing: how much arrived, how much is left, what went out and why.
 *
 * Read from the cost layers and the draw ledger, never from a column on the deal — the layers
 * are where the truth is, and a cached quantity could only ever disagree with them.
 *
 * **`quantity_received` is derived, never `SUM(quantity_received)`.** A transfer mints a fresh
 * layer at the destination with its own received quantity, so that sum double-counts every unit
 * ever moved between warehouses. Remaining plus everything that left is the honest figure.
 *
 * **`unit` is what these quantities are counted in**, read off the layers rather than off the
 * deal's items, because the layers are what they were summed from. Bags weighed by the kilo make
 * 174.900 an ordinary figure and the screen printing it bare gave the owner no way to tell a kilo
 * from a bag. A deal holding two units has no one word for its total, so it names neither.
 *
 * A reversed movement is excluded whole: `CreditBackStockBatches` returns a draw in its entirety
 * and there is no partial credit anywhere in Inventory, so «the movement a live reversal points
 * at» is exactly the set to walk past — **with one exception, and it is the whole of سعر السادة**:
 * a cancellation does not credit a priced layer back to the deal at all, it hands those goods to
 * the company. They left and stayed left, so they are counted sold.
 *
 * Internal transfers are excluded too — the source draw is not a sale, and its stock is sitting
 * in the destination layer carrying the same deal.
 */
final class DealStockPosition
{
    /**
     * @return array{
     *     quantity_remaining: string,
     *     quantity_sold: string,
     *     quantity_damaged: string,
     *     quantity_short: string,
     *     quantity_received: string,
     *     unit: string|null,
     *     unit_label: string|null,
     *     cost_remaining: string,
     *     cost_sold: string,
     *     cost_damaged: string,
     *     cost_short: string,
     *     per_item: list<array<string, string|int>>
     * }
     */
    public function __invoke(int $dealId): array
    {
        $unit = $this->unitOf($dealId);

        $remaining = DB::table('stock_batches')
            ->where('investor_deal_id', $dealId)
            ->whereNull('deleted_at')
            ->selectRaw('stock_item_id, SUM(quantity_remaining) as qty, SUM(quantity_remaining * unit_cost) as cost')
            ->groupBy('stock_item_id')
            ->get();

        $draws = DB::table('stock_batch_consumptions as c')
            ->join('stock_batches as b', 'b.id', '=', 'c.stock_batch_id')
            ->join('stock_movements as m', 'm.id', '=', 'c.stock_movement_id')
            ->where('b.investor_deal_id', $dealId)
            ->whereNull('b.deleted_at')
            ->whereNull('c.deleted_at')
            ->whereNull('m.deleted_at')
            ->where('m.movement_type', '<>', 'internal_transfer')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('stock_movements as r')
                ->whereColumn('r.reverses_movement_id', 'm.id')
                ->whereNull('r.deleted_at')
                // **Except the draw a cancellation never gave back.** A printed line's priced
                // layers are not credited to the deal when the customer refuses the parcel —
                // `ReverseOrderStockDeduction` hands them to the company at what the company
                // paid, because the investor was paid for them the day they left the shelf. Those
                // kilos are gone from his layers for good, so walking past the draw would drop
                // them out of `quantity_sold` *and* out of the `quantity_received` derived from
                // it: a 500 kg shipment reading «وصل ٢٠٠ · بِيع ٠». They were sold — to the
                // company. A restatement, which does credit everything back, is excluded as
                // before: its reversal does not belong to a cancelled order.
                ->where(fn ($credited) => $credited
                    ->whereNull('b.printing_sale_price')
                    ->orWhereNotExists(fn ($cancelled) => $cancelled->select(DB::raw(1))
                        ->from('orders as o')
                        ->whereColumn('o.id', 'r.reference_id')
                        ->where('o.status', OrderStatus::Cancelled->value)
                        ->whereNull('o.deleted_at'))))
            ->selectRaw('b.stock_item_id, m.movement_type, m.adjustment_reason, m.from_warehouse_id, SUM(c.quantity) as qty, SUM(c.total_cost) as cost')
            ->groupBy('b.stock_item_id', 'm.movement_type', 'm.adjustment_reason', 'm.from_warehouse_id')
            ->get();

        $buckets = ['sold' => ['0', '0'], 'damaged' => ['0', '0'], 'short' => ['0', '0']];
        $perItem = [];

        foreach ($remaining as $row) {
            $perItem[(int) $row->stock_item_id] ??= $this->emptyItem((int) $row->stock_item_id);
            $perItem[(int) $row->stock_item_id]['quantity_remaining'] = (string) $row->qty;
            $perItem[(int) $row->stock_item_id]['cost_remaining'] = Money::round((string) $row->cost);
        }

        foreach ($draws as $row) {
            $bucket = $this->bucketOf($row->movement_type, $row->adjustment_reason, $row->from_warehouse_id);

            if ($bucket === null) {
                continue;
            }

            $buckets[$bucket][0] = bcadd($buckets[$bucket][0], (string) $row->qty, 3);
            $buckets[$bucket][1] = bcadd($buckets[$bucket][1], (string) $row->cost, 2);

            $itemId = (int) $row->stock_item_id;
            $perItem[$itemId] ??= $this->emptyItem($itemId);
            $perItem[$itemId]['quantity_'.$bucket] = bcadd($perItem[$itemId]['quantity_'.$bucket], (string) $row->qty, 3);
        }

        $remainingQty = array_reduce(
            $perItem,
            fn (string $carry, array $item) => bcadd($carry, $item['quantity_remaining'], 3),
            '0',
        );
        $remainingCost = array_reduce(
            $perItem,
            fn (string $carry, array $item) => bcadd($carry, $item['cost_remaining'], 2),
            '0',
        );

        return [
            'quantity_remaining' => $this->qty($remainingQty),
            'quantity_sold' => $this->qty($buckets['sold'][0]),
            'quantity_damaged' => $this->qty($buckets['damaged'][0]),
            'quantity_short' => $this->qty($buckets['short'][0]),
            'quantity_received' => $this->qty(bcadd(
                $remainingQty,
                bcadd($buckets['sold'][0], bcadd($buckets['damaged'][0], $buckets['short'][0], 3), 3),
                3,
            )),
            'unit' => $unit?->value,
            'unit_label' => $unit?->label(),
            'cost_remaining' => Money::round($remainingCost),
            'cost_sold' => Money::round($buckets['sold'][1]),
            'cost_damaged' => Money::round($buckets['damaged'][1]),
            'cost_short' => Money::round($buckets['short'][1]),
            'per_item' => array_values($perItem),
        ];
    }

    /**
     * The single unit every one of this deal's layers is counted in, or null when they disagree.
     *
     * A layer's `unit` is a snapshot taken at receipt, which is the right one to read: it is the
     * unit the quantity beside it was measured in, whatever the shelf has been renamed to since.
     */
    private function unitOf(int $dealId): ?PricingUnit
    {
        $units = DB::table('stock_batches')
            ->where('investor_deal_id', $dealId)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('unit');

        return $units->count() === 1 ? PricingUnit::tryFrom((string) $units->first()) : null;
    }

    /**
     * The one mapping from a movement to a bucket, so nine read sites cannot disagree.
     *
     * `unit_change` maps to nothing: it is the write-down `SetStockItemUnit` posts for itself,
     * and it cannot occur on a funded shelf anyway because that action refuses one.
     */
    private function bucketOf(string $type, ?string $reason, ?int $fromWarehouse): ?string
    {
        if ($type === 'order_fulfillment') {
            return 'sold';
        }

        if ($type === 'scrap_loss') {
            return 'damaged';
        }

        if ($type !== 'adjustment' || $fromWarehouse === null) {
            return null;
        }

        return match ($reason) {
            'damage' => 'damaged',
            'shortage', 'count_correction' => 'short',
            default => null,
        };
    }

    /**
     * @return array<string, string|int>
     */
    private function emptyItem(int $stockItemId): array
    {
        return [
            'stock_item_id' => $stockItemId,
            'quantity_remaining' => '0',
            'quantity_sold' => '0',
            'quantity_damaged' => '0',
            'quantity_short' => '0',
            'cost_remaining' => '0.00',
        ];
    }

    private function qty(string $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }
}
