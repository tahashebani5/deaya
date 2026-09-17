<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Exceptions\BatchIsFullyConsumed;
use App\Domain\Inventory\Exceptions\BatchIsFundedByADeal;
use App\Domain\Inventory\Exceptions\RevaluationExceedsRemaining;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBatchRevaluation;
use Illuminate\Support\Facades\DB;

/**
 * Changes what a quantity of stock is carried at — all of a cost layer, or part of it.
 *
 * **The one thing in this domain that moves money without moving stock.** Every other write here
 * changes a balance and leaves a `stock_movements` row explaining it. This changes no balance at
 * all: `warehouse_stocks.quantity` is untouched, and the sum of `quantity_remaining` across the
 * layers behind it is exactly what it was before. What changes is what those layers say the
 * stock cost.
 *
 * **Prospective, never retrospective.** A quantity already drawn off this layer recorded its
 * cost in `stock_batch_consumptions` at the moment it left, and `order_items.material_cost` was
 * frozen from those rows by `DeductOrderStock`. Nothing here touches either. A correction that
 * silently restated closed orders' profit would be a way to rewrite history, and the honest
 * consequence is stated instead: revaluing a layer that is half gone fixes the half that is
 * left. {@see BatchIsFullyConsumed} is that rule at its limit.
 *
 * **A partial revaluation splits the layer, and the repriced quantity stays on the original
 * row.** That is the whole trick, and it is worth the paragraph:
 *
 * ```
 * before   #40 — received 500, remaining 300 (200 already drawn) @ 0.000
 *
 * revalue 100 @ 3.500
 *
 * #40  remaining 100   received 300   @ 3.500   ← consumed first
 * #41  remaining 200   received 200   @ 0.000   ← the untouched remainder
 *      split_from_batch_id = 40
 * ```
 *
 * FIFO orders by `received_at` and breaks ties on `id`, so the *original* row is always drawn
 * first. Putting the new cost there is what makes the repriced quantity the next thing off the
 * shelf, without a second ordering column and without {@see ConsumeStockBatchesFifo} changing by
 * a line. The alternative — a new row carrying the new cost, nudged earlier — would have meant
 * either a third term in the one query the whole costing system depends on, or a `received_at`
 * that lies about the age of the stock and walks further back with every split.
 *
 * It also keeps the parent's own arithmetic honest: `received − remaining` is still 200, exactly
 * what its consumption rows record, because the drawn quantity stays with the row that holds
 * them.
 *
 * **A batch from a purchase order is repriced like any other.** The API says so before somebody
 * does it — see `StockBatchResource::$purchase_order_id` — but refusing would exclude the
 * commonest correction there is: the vendor's invoice disagreeing with the delivery note.
 *
 * Takes the balance row's lock **before touching any batch row**, in the same order every other
 * write in this domain takes it, so a revaluation and a fulfillment drawing on the same shelf
 * cannot interleave. See {@see ApplyStockChange}.
 */
final class RevalueStockBatch
{
    public function __construct(private readonly ApplyStockChange $applyStockChange) {}

    /**
     * @param  string|null  $quantity  How much of the layer to reprice. Null is all of what is
     *                                 left, which is the common case and the one that needs no
     *                                 split.
     *
     * @throws BatchIsFullyConsumed
     * @throws RevaluationExceedsRemaining
     */
    public function __invoke(
        StockBatch $batch,
        string $unitCost,
        string $reason,
        int $userId,
        ?string $quantity = null,
    ): StockBatch {
        return DB::transaction(function () use ($batch, $unitCost, $reason, $userId, $quantity): StockBatch {
            // Before the batch is re-read, and before anything is written: this is the lock every
            // consumption of this shelf already queues behind.
            $this->applyStockChange->lockBalance(
                (int) $batch->warehouse_id,
                (int) $batch->stock_item_id,
            );

            // Re-read under the lock. The remaining quantity this decision is made against may
            // have moved between the request being validated and the lock being granted — an
            // order going to print is exactly the thing that would move it.
            $batch = $batch->newQuery()->lockForUpdate()->findOrFail($batch->getKey());

            $remaining = (string) $batch->quantity_remaining;

            if (bccomp($remaining, '0', 3) <= 0) {
                throw BatchIsFullyConsumed::make((int) $batch->getKey());
            }

            // **A layer somebody else paid for is not repriced by us.** Raising the cost of a
            // funded layer lowers the investor's share of every future sale from it and lowering
            // it inflates his — one party to the arrangement moving a number the other party is
            // paid on, behind a grant («تصحيح التكلفة») that was never about that. The business
            // decision is that a deal's cost is agreed once; a late invoice becomes a deal
            // expense instead, which is visible on his own statement.
            if ($batch->investor_deal_id !== null) {
                throw BatchIsFundedByADeal::make((int) $batch->getKey());
            }

            $repriced = $quantity ?? $remaining;

            if (bccomp($repriced, $remaining, 3) > 0) {
                throw RevaluationExceedsRemaining::make($repriced, $remaining);
            }

            $oldCost = (string) $batch->unit_cost;

            // Split first, while the parent still holds the whole remainder: the child takes the
            // part that is *not* being repriced, so the parent keeps the FIFO position that puts
            // the corrected stock next off the shelf.
            if (bccomp($repriced, $remaining, 3) < 0) {
                $this->splitOffRemainder($batch, bcsub($remaining, $repriced, 3));
            }

            $batch->forceFill([
                'unit_cost' => $unitCost,
                'quantity_remaining' => $repriced,
                'revalued_at' => now(),
            ])->save();

            $this->record($batch, $repriced, $oldCost, $unitCost, $reason, $userId);

            return $batch->refresh();
        });
    }

    /**
     * Moves the quantity that is *not* being repriced onto a row of its own, at the cost it
     * already had.
     *
     * Everything about where the stock came from is copied rather than re-derived: the same
     * `received_at` (so it does not get younger by being split), the same source, the same
     * arrival line and movement. `quantity_received` moves across with it, so the two rows'
     * received quantities still add up to what the layer held and neither one's
     * `received − remaining` overstates what was drawn from it.
     */
    private function splitOffRemainder(StockBatch $batch, string $quantity): StockBatch
    {
        $child = new StockBatch;
        $child->warehouse_id = $batch->warehouse_id;
        $child->stock_item_id = $batch->stock_item_id;
        $child->source_type = $batch->source_type;
        $child->stock_arrival_item_id = $batch->stock_arrival_item_id;
        $child->stock_movement_id = $batch->stock_movement_id;
        // The third and last place `investor_deal_id` is written. Funded layers are refused
        // revaluation outright, so this line should be unreachable for them — it is here because
        // «should be unreachable» is exactly the assumption that turns a deal's stock into the
        // company's the day somebody relaxes the guard above.
        $child->investor_deal_id = $batch->investor_deal_id;
        $child->split_from_batch_id = $batch->getKey();
        $child->unit_cost = $batch->unit_cost;
        $child->quantity_received = $quantity;
        $child->quantity_remaining = $quantity;
        $child->unit = $batch->unit;
        $child->received_at = $batch->received_at;
        $child->save();

        $batch->quantity_received = bcsub((string) $batch->quantity_received, $quantity, 3);

        return $child;
    }

    /**
     * The event, as opposed to the two rows it left behind.
     *
     * Written even when the cost did not actually change — somebody confirming a figure is a
     * decision, and a reason nobody can find is the failure this table exists to prevent.
     */
    private function record(
        StockBatch $batch,
        string $quantity,
        string $oldCost,
        string $newCost,
        string $reason,
        int $userId,
    ): void {
        $revaluation = new StockBatchRevaluation;
        $revaluation->stock_batch_id = $batch->getKey();
        $revaluation->quantity = $quantity;
        $revaluation->old_unit_cost = $oldCost;
        $revaluation->new_unit_cost = $newCost;
        $revaluation->reason = $reason;
        $revaluation->user_id = $userId;
        $revaluation->save();
    }
}
