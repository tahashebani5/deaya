<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\DTOs\StockMovementData;
use App\Domain\Inventory\Enums\StockAdjustmentReason;
use App\Domain\Inventory\Exceptions\StockItemIsFundedByADeal;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Inventory\StockLedgerTest;

/**
 * The one way a shelf's unit — and, through it, every `WarehouseStock` and `StockBatch`
 * snapshotted against it — ever changes after creation.
 *
 * Replaces the `SetStockUnit` that used to hang off a *product*. The requirement has not changed,
 * only its owner: a product bought in by weight and sold by the piece still needs
 * `products.pricing_unit` and this to disagree. What has changed is that the pile now has exactly
 * one owner for the answer, so كيس شحن سادة and كيس شحن مطبوع can no longer insist that one heap
 * of bags is counted two different ways.
 *
 * **What is on the shelf is discarded, not carried over, and that is the whole point.** 200 bags
 * are not 200 kilograms. This used to relabel the balance and leave the figure alone, so a shelf
 * counted by hand became a shelf claiming a weight nobody put on a scale — and every costing,
 * shortage and reorder answer downstream inherited it. A quantity is only meaningful in the unit
 * it was measured in, so changing the unit ends it.
 *
 * **It leaves through the ledger, not behind it.** {@see StockLedgerTest}
 * states the invariant this context exists to hold — for every (warehouse, stock item) the
 * balance equals the signed sum of its movements — so a balance quietly set to zero would break
 * the one rule all the machinery here serves. Each non-empty shelf is emptied by an `Adjustment`
 * out, recorded **before** the unit changes so it carries the unit the stock was actually counted
 * in. That is also what keeps «لماذا اختفى الرصيد؟» answerable a year later, by name and by date.
 *
 * **Re-picking the unit it already has does nothing at all.** Discarding a shelf because somebody
 * opened the sheet and tapped what was already selected would be the worst possible reading of
 * this endpoint.
 *
 * **Every balance and every batch moves in the same transaction, under the same locks
 * {@see ApplyStockChange} already uses for cross-row consistency** — so a stock movement racing
 * this update either sees the unit before or after, never a balance in one unit sitting beside a
 * batch in another. Locked in ascending warehouse order for the same deadlock-avoidance reason
 * {@see ApplyStockChange::moveTransferBalances()} documents.
 */
final class SetStockItemUnit
{
    public function __construct(
        private readonly RecordStockMovement $recordMovement,
    ) {}

    /**
     * @param  int  $actorId  who is answerable for discarding the balances — the adjustment rows
     *                        name them, exactly as every other movement names its author.
     */
    public function __invoke(StockItem $item, PricingUnit $unit, int $actorId): StockItem
    {
        // Nothing to do, and nothing to destroy. Checked before the transaction opens so the
        // common "opened the sheet, changed nothing" path takes no locks at all.
        if ($item->unit === $unit) {
            return $item;
        }

        // **Refused while somebody else's money is on this shelf.** `discardBalances()` below
        // posts a real decreasing adjustment for every warehouse holding the item, which would
        // FIFO-consume a deal's entire holding and book it as a loss nobody caused. The unit
        // waits until the shelf is empty of funded stock.
        $funded = StockBatch::query()
            ->where('stock_item_id', $item->getKey())
            ->whereNotNull('investor_deal_id')
            ->where('quantity_remaining', '>', 0)
            ->exists();

        if ($funded) {
            throw StockItemIsFundedByADeal::make();
        }

        // **Outside the transaction, and before the unit changes.** `RecordStockMovement` opens
        // its own transaction and resolves the unit it stamps from the stock item — so this has
        // to run while that still says the *old* unit, or the bags would be recorded as leaving
        // in kilograms.
        $this->discardBalances($item, $actorId);

        return DB::transaction(function () use ($item, $unit): StockItem {
            $item->unit = $unit;
            $item->save();

            WarehouseStock::query()
                ->where('stock_item_id', $item->getKey())
                ->orderBy('warehouse_id')
                ->lockForUpdate()
                ->get()
                ->each(function (WarehouseStock $stock) use ($unit): void {
                    $stock->unit = $unit;
                    $stock->save();
                });

            // Emptied by the adjustments above, but relabelled all the same: a fully consumed
            // batch keeps its row for the cost history, and a spent row left in the old unit
            // would be the one thing in this item's records still saying "piece".
            StockBatch::query()
                ->where('stock_item_id', $item->getKey())
                ->orderBy('warehouse_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(function (StockBatch $batch) use ($unit): void {
                    $batch->unit = $unit;
                    $batch->save();
                });

            return $item;
        });
    }

    /**
     * Takes every non-empty shelf holding this item down to zero, one adjustment each.
     *
     * A zero balance is skipped rather than adjusted by zero: a ledger line recording no event is
     * noise in a feed people read to explain a discrepancy.
     */
    private function discardBalances(StockItem $item, int $actorId): void
    {
        $balances = WarehouseStock::query()
            ->where('stock_item_id', $item->getKey())
            ->where('quantity', '>', 0)
            ->orderBy('warehouse_id')
            ->get();

        foreach ($balances as $balance) {
            ($this->recordMovement)(StockMovementData::adjustment(
                [
                    'stock_item_id' => $item->getKey(),
                    'warehouse_id' => $balance->warehouse_id,
                    'direction' => 'decrease',
                    'quantity' => (string) $balance->quantity,
                    'notes' => 'تصفير الرصيد عند تغيير وحدة المخزون من '
                        .$item->unit->label().' — الكمية كانت محسوبة بالوحدة القديمة',
                    // Not a loss anybody caused, and now the ledger says so rather than leaving
                    // it to be read as one. It is also the reason the shape CHECK on
                    // `stock_movements` can require a reason on every decrease: without this the
                    // constraint would reject the one write the system makes for itself.
                    'adjustment_reason' => StockAdjustmentReason::UnitChange->value,
                ],
                $actorId,
            ));
        }
    }
}
