<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\DTOs\BatchDraw;
use App\Domain\Inventory\Exceptions\InsufficientBatchStock;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBatchConsumption;
use App\Domain\Inventory\Support\Money;

/**
 * Draws a quantity out of the oldest cost layers first, and records exactly what it took.
 *
 * Called only from {@see ApplyStockChange::decrease()}, after the balance row is already locked
 * and written — never on its own. Locking the candidate batches here, inside that same
 * transaction, is what keeps `SUM(quantity_remaining)` from ever being read mid-update by a
 * concurrent movement against the same (warehouse, size).
 */
final class ConsumeStockBatchesFifo
{
    /**
     * @return list<BatchDraw>
     */
    public function __invoke(int $warehouseId, int $stockItemId, string $quantity, int $stockMovementId): array
    {
        $batches = StockBatch::query()
            ->where('warehouse_id', $warehouseId)
            ->where('stock_item_id', $stockItemId)
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = $quantity;
        $draws = [];

        foreach ($batches as $batch) {
            if (bccomp($remaining, '0', 3) <= 0) {
                break;
            }

            $take = bccomp((string) $batch->quantity_remaining, $remaining, 3) < 0
                ? (string) $batch->quantity_remaining
                : $remaining;

            if (bccomp($take, '0', 3) <= 0) {
                continue;
            }

            $batch->quantity_remaining = bcsub((string) $batch->quantity_remaining, $take, 3);
            $batch->save();

            $totalCost = Money::round(bcmul($take, (string) $batch->unit_cost, 8));

            $consumption = new StockBatchConsumption;
            $consumption->stock_batch_id = $batch->id;
            $consumption->stock_movement_id = $stockMovementId;
            $consumption->quantity = $take;
            $consumption->unit_cost = $batch->unit_cost;
            $consumption->total_cost = $totalCost;
            $consumption->save();

            $draws[] = new BatchDraw(
                stockBatchId: $batch->id,
                quantity: $take,
                unitCost: (string) $batch->unit_cost,
                totalCost: $totalCost,
                receivedAt: $batch->received_at->toISOString(),
                sourceType: $batch->source_type,
                stockArrivalItemId: $batch->stock_arrival_item_id,
                stockMovementId: $batch->stock_movement_id,
                investorDealId: $batch->investor_deal_id,
                printingSalePrice: $batch->printing_sale_price === null
                    ? null
                    : (string) $batch->printing_sale_price,
            );

            $remaining = bcsub($remaining, $take, 3);
        }

        // Should never happen — ApplyStockChange::decrease() already refused this call if
        // `warehouse_stocks.quantity` itself was short. This is the loud failure for the day the
        // two have somehow drifted apart, rather than a movement silently costed short.
        if (bccomp($remaining, '0', 3) > 0) {
            $available = Money::sum(...array_map(fn (BatchDraw $draw) => $draw->quantity, $draws));

            throw InsufficientBatchStock::make($warehouseId, $stockItemId, $available, $quantity);
        }

        return $draws;
    }
}
