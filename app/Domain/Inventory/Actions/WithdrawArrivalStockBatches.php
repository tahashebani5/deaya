<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Exceptions\ArrivalBatchAlreadyDrawnOn;
use App\Domain\Inventory\Exceptions\ArrivalBatchWasRevalued;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBatchConsumption;
use App\Domain\Inventory\Support\Money;

/**
 * Takes back the exact cost layers one arrival opened, because the receipt that opened them was
 * entered in error.
 *
 * Called only from {@see ApplyStockChange::withdrawArrival()}, after the balance row is already
 * locked — never on its own. The mirror of {@see CreditBackStockBatches}, and the opposite of
 * {@see ConsumeStockBatchesFifo} in the one way that matters: **it does not draw FIFO.** The
 * layers this undoes are the erroneous ones, named by the arrival movement that opened them; a
 * FIFO draw would take the oldest stock on the shelf instead and leave the mistake sitting there,
 * repricing goods nobody had questioned in order to hide the ones somebody had.
 *
 * **Nothing is deleted.** The layer stays, drawn down to nothing, with a
 * {@see StockBatchConsumption} row against the reversal movement explaining where it went — so
 * `SUM(quantity_remaining)` is still explained by the consumption rows beneath it, and
 * `StockBatchLedgerTest`'s invariant holds across a reversal exactly as it does across a sale.
 *
 * **Scoped to the arrival's own warehouse**, because `stock_movement_id` alone does not identify
 * a layer: {@see ApplyStockChange::relocateBatches()} deliberately copies it onto the layers an
 * internal transfer recreates at the destination, so the arrival's id can legitimately appear on
 * a shelf in another building. Those copies are somebody else's stock now — and in any case the
 * transfer drew on the source layer, so the guard below has already refused the whole reversal.
 *
 * @throws ArrivalBatchAlreadyDrawnOn
 * @throws ArrivalBatchWasRevalued
 */
final class WithdrawArrivalStockBatches
{
    /**
     * @return string the total quantity taken back, for the caller to shrink the balance by
     */
    public function __invoke(
        int $warehouseId,
        int $stockItemId,
        int $reversedMovementId,
        int $stockMovementId,
    ): string {
        // Locked in ascending id order, the same deadlock-avoidance reasoning
        // CreditBackStockBatches and RecordStockMovement::moveTransferBalances() both document.
        $batches = StockBatch::query()
            ->where('warehouse_id', $warehouseId)
            ->where('stock_item_id', $stockItemId)
            ->where('stock_movement_id', $reversedMovementId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $withdrawn = '0';

        foreach ($batches as $batch) {
            $this->guardUntouched($batch);

            $quantity = (string) $batch->quantity_remaining;

            $batch->quantity_remaining = '0.000';
            $batch->save();

            $consumption = new StockBatchConsumption;
            $consumption->stock_batch_id = $batch->id;
            $consumption->stock_movement_id = $stockMovementId;
            $consumption->quantity = $quantity;
            $consumption->unit_cost = $batch->unit_cost;
            $consumption->total_cost = Money::round(bcmul($quantity, (string) $batch->unit_cost, 8));
            $consumption->save();

            $withdrawn = bcadd($withdrawn, $quantity, 3);
        }

        return $withdrawn;
    }

    /**
     * The layer must be exactly as the arrival left it: never drawn on, never repriced.
     *
     * The consumption check is what makes «لم يُصرف منها شيء» true rather than merely likely —
     * see {@see ArrivalBatchAlreadyDrawnOn} for the cancelled-order case that makes
     * `quantity_remaining` an unreliable witness.
     *
     * @throws ArrivalBatchAlreadyDrawnOn
     * @throws ArrivalBatchWasRevalued
     */
    private function guardUntouched(StockBatch $batch): void
    {
        if ($batch->revalued_at !== null) {
            throw ArrivalBatchWasRevalued::make((int) $batch->getKey());
        }

        $drawnOn = StockBatchConsumption::query()
            ->where('stock_batch_id', $batch->getKey())
            ->exists();

        if ($drawnOn) {
            throw ArrivalBatchAlreadyDrawnOn::make((int) $batch->getKey());
        }
    }
}
