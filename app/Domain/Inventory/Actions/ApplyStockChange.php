<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Delivery\Actions\DeleteRegion;
use App\Domain\Inventory\DTOs\BatchDraw;
use App\Domain\Inventory\DTOs\StockChangeResult;
use App\Domain\Inventory\Enums\StockBatchSourceType;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Exceptions\UnitOfMeasurementMismatch;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBatchConsumption;
use App\Domain\Inventory\Models\WarehouseStock;
use Illuminate\Support\Carbon;

/**
 * Moves one balance, under a lock — and, since batch costing landed, the cost layers behind it.
 *
 * **This is the only code in the application that writes `warehouse_stocks.quantity`**, which is
 * why the column is absent from that model's fillable list. Everything else records a reason;
 * this applies it. The same is now true of `stock_batches.quantity_remaining`: every call here
 * keeps `SUM(quantity_remaining)` for a (warehouse, size) equal to that pair's balance, under the
 * same lock, in the same transaction — batch and balance can never drift apart because nothing
 * ever writes one without the other.
 *
 * The lock is the whole point. Read-check-write on a balance is the textbook lost update: two
 * fulfillments of 60 against a shelf of 100 would both read 100, both find it sufficient, and
 * both write 40 — leaving 40 on a shelf that has had 120 taken off it. `lockForUpdate` makes
 * the second transaction wait for the first to commit and then see 40, at which point it
 * correctly refuses. Same reasoning as
 * {@see DeleteRegion}, which locks the parent city rather than
 * counting its regions.
 *
 * Must be called inside a transaction — a lock outside one is released immediately and
 * guarantees nothing. {@see RecordStockMovement} is the caller that provides it.
 */
final class ApplyStockChange
{
    public function __construct(
        private readonly ConsumeStockBatchesFifo $consumeBatches,
        private readonly CreditBackStockBatches $creditBackBatches,
        private readonly WithdrawArrivalStockBatches $withdrawArrivalBatches,
    ) {}

    /**
     * Takes `$quantity` out of the warehouse's balance for this size, drawing the cost layers
     * behind it down oldest-first, or refuses.
     *
     * @throws InsufficientStock
     * @throws UnitOfMeasurementMismatch
     */
    public function decrease(
        int $warehouseId,
        int $stockItemId,
        string $quantity,
        PricingUnit $unit,
        int $stockMovementId,
    ): StockChangeResult {
        $stock = $this->lockedRow($warehouseId, $stockItemId);

        // No row at all means this size has never been here, which is the same answer as a
        // balance of zero — and gives the storekeeper the number they need either way. Checked
        // before the balance rather than folded into the comparison: there is nothing to
        // decrement, so even a quantity of zero has no row to write it to.
        if ($stock === null) {
            throw InsufficientStock::make('0.000', $quantity);
        }

        $this->guardUnit($stock, $unit);

        $available = (string) $stock->quantity;

        if (bccomp($available, $quantity, 3) < 0) {
            throw InsufficientStock::make($available, $quantity);
        }

        $stock->quantity = bcsub($available, $quantity, 3);
        $stock->save();

        $consumed = ($this->consumeBatches)($warehouseId, $stockItemId, $quantity, $stockMovementId);

        return new StockChangeResult($stock, $consumed);
    }

    /**
     * Adds `$quantity` to the warehouse's balance, creating the line the first time a size
     * arrives somewhere, and opens one new cost layer priced at `$unitCost`.
     *
     * `$unit` is only ever written on that first arrival — see {@see WarehouseStock}'s docblock —
     * and checked against, never rewritten, on every arrival after.
     *
     * `$unitCost` of `'0.000'` is how "this arrival's cost was never recorded" is represented —
     * the same treatment the opening-balance backfill gives to stock that predates batch costing
     * entirely. Refusing the movement instead would block a storekeeper from recording stock that
     * genuinely arrived, over a bookkeeping gap; the honest fix is entering the cost later, not
     * blocking the arrival.
     *
     * @throws UnitOfMeasurementMismatch
     */
    public function increase(
        int $warehouseId,
        int $stockItemId,
        string $quantity,
        PricingUnit $unit,
        string $unitCost,
        StockBatchSourceType $sourceType,
        ?int $stockArrivalItemId = null,
        ?int $stockMovementId = null,
        ?int $investorDealId = null,
        ?string $printingSalePrice = null,
    ): WarehouseStock {
        $stock = $this->growBalance($warehouseId, $stockItemId, $quantity, $unit);

        $this->openBatch(
            $warehouseId, $stockItemId, $quantity, $unitCost, $unit,
            $sourceType, $stockArrivalItemId, Carbon::now(), $stockMovementId, $investorDealId,
            $printingSalePrice,
        );

        return $stock;
    }

    /**
     * Grows the destination balance of an internal transfer and recreates, at the new warehouse,
     * exactly the cost layers `decrease()` just drew from the source — same `received_at`, same
     * {@see StockBatchSourceType}, same arrival traceability. The stock did not get younger or
     * change where its cost came from by moving shelves, so neither is allowed to change here.
     *
     * Deliberately separate from `increase()`: a transfer never invents a cost of its own, and a
     * single new batch at one blended cost would both invert FIFO age (a fresh `received_at`
     * makes old stock look newest) and average away the very cost layers FIFO exists to keep
     * distinct.
     *
     * @param  list<BatchDraw>  $consumed  what the paired `decrease()` call drew from the source
     */
    public function relocateBatches(
        int $warehouseId,
        int $stockItemId,
        PricingUnit $unit,
        array $consumed,
    ): WarehouseStock {
        $total = array_reduce($consumed, fn (string $carry, BatchDraw $draw) => bcadd($carry, $draw->quantity, 3), '0');

        $stock = $this->growBalance($warehouseId, $stockItemId, $total, $unit);

        foreach ($consumed as $draw) {
            $this->openBatch(
                $warehouseId, $stockItemId, $draw->quantity, $draw->unitCost, $unit,
                $draw->sourceType, $draw->stockArrivalItemId, Carbon::parse($draw->receivedAt),
                // The event that brought this stock into the business, not the transfer that
                // moved it — the same reasoning `received_at` and `source_type` above follow.
                // The transfer is fully recorded as its own `stock_movements` row.
                $draw->stockMovementId,
                // **And whoever financed it.** Missing this copy would turn an investor's stock
                // into the company's the first time somebody moved half a shipment between
                // warehouses, with every quantity in the system still perfectly correct and
                // nothing at all to notice — which is why it has a scenario test of its own.
                $draw->investorDealId,
                // And the price it sells to the press at, for the same reason and with the same
                // silence: a lorry moved to the workshop shelf would be printed at cost.
                $draw->printingSalePrice,
            );
        }

        return $stock;
    }

    /**
     * Grows a balance by crediting back exactly the cost layers `$reversedMovementId` drew from —
     * never a fresh batch at an averaged cost. See {@see CreditBackStockBatches} for why: a new
     * batch would stamp a fresh `received_at` on stock that was, by definition, among the oldest
     * on the shelf, inverting FIFO order for it; and blending several layers into one average is
     * exactly the mixing FIFO exists to avoid.
     *
     * The total is read before the balance is grown rather than accumulated by the credit step
     * itself, so this can call `growBalance()` first — the same balance-row-locked-before-any-
     * batch-row ordering every other method here uses.
     */
    public function creditBack(
        int $warehouseId,
        int $stockItemId,
        PricingUnit $unit,
        int $reversedMovementId,
        bool $purchasedLayersBelongToTheCompany = false,
    ): WarehouseStock {
        $total = (string) StockBatchConsumption::query()
            ->where('stock_movement_id', $reversedMovementId)
            ->sum('quantity');

        $stock = $this->growBalance($warehouseId, $stockItemId, $total, $unit);

        $bought = ($this->creditBackBatches)($reversedMovementId, $purchasedLayersBelongToTheCompany);

        foreach ($bought as $draw) {
            $this->openBatch(
                $warehouseId,
                $stockItemId,
                $draw['quantity'],
                // What the company paid for it, which is the only cost it has now. The layer it
                // came off was drawn down and paid for; this is a different lot of goods in
                // every sense that matters to a ledger.
                $draw['printing_sale_price'],
                $unit,
                StockBatchSourceType::Adjustment,
                stockArrivalItemId: null,
                receivedAt: Carbon::now(),
                stockMovementId: null,
                // The company's, now — «استلم الزبون ما استلمش، المطبعة تتحمّل». No deal, so no
                // price either: nobody sells this to the press a second time.
                investorDealId: null,
                printingSalePrice: null,
            );
        }

        return $stock;
    }

    /**
     * Shrinks a balance by taking back exactly the cost layers `$reversedMovementId` opened —
     * never a FIFO draw from the oldest stock on the shelf. See
     * {@see WithdrawArrivalStockBatches} for why: the layers this undoes are the erroneous ones,
     * and drawing FIFO instead would consume somebody else's oldest stock while leaving the
     * mistake on the shelf.
     *
     * The balance row is locked before any batch row is read, the same ordering every other
     * method here follows. The two guards inside the withdrawal — nothing drawn from the layer,
     * nothing repriced on it — are what make the total below safe to subtract: a layer that has
     * never been touched still holds precisely what the arrival put on it.
     *
     * @throws InsufficientStock
     * @throws UnitOfMeasurementMismatch
     */
    public function withdrawArrival(
        int $warehouseId,
        int $stockItemId,
        PricingUnit $unit,
        int $reversedMovementId,
        int $stockMovementId,
    ): WarehouseStock {
        $stock = $this->lockedRow($warehouseId, $stockItemId);

        $withdrawn = ($this->withdrawArrivalBatches)(
            $warehouseId, $stockItemId, $reversedMovementId, $stockMovementId,
        );

        // Unreachable while the guards above hold: a layer nobody has drawn on cannot exist
        // without the balance that was grown alongside it, in the same transaction. Kept as the
        // loud failure for the day the two have somehow drifted apart — the same role the
        // remainder check at the end of ConsumeStockBatchesFifo plays.
        if ($stock === null) {
            throw InsufficientStock::make('0.000', $withdrawn);
        }

        $this->guardUnit($stock, $unit);

        $available = (string) $stock->quantity;

        if (bccomp($available, $withdrawn, 3) < 0) {
            throw InsufficientStock::make($available, $withdrawn);
        }

        $stock->quantity = bcsub($available, $withdrawn, 3);
        $stock->save();

        return $stock;
    }

    /**
     * Acquires the row lock for a (warehouse, size) balance without changing anything.
     *
     * Used only to fix the order two locks are taken in for an internal transfer — see
     * `RecordStockMovement::moveTransferBalances()` — so that two transfers running in opposite
     * directions between the same pair of warehouses cannot deadlock. Re-locking a row already
     * held by this transaction is a no-op, so calling this before `decrease()`/`relocateBatches()`
     * touch the same row costs nothing.
     */
    public function lockBalance(int $warehouseId, int $stockItemId): void
    {
        $this->lockedRow($warehouseId, $stockItemId);
    }

    private function growBalance(int $warehouseId, int $stockItemId, string $quantity, PricingUnit $unit): WarehouseStock
    {
        $stock = $this->lockedRow($warehouseId, $stockItemId);

        if ($stock === null) {
            // Created inside the caller's transaction and under the same unique index that makes
            // one row per (warehouse, size) a guarantee — so two concurrent first arrivals
            // cannot both insert. The loser fails on the index rather than silently producing a
            // second balance nobody would ever reconcile.
            //
            // Assigned rather than mass-assigned: none of these four is fillable, precisely so
            // that no payload can reach them. `create()` here would be discarded under strict
            // mode — and, worse, would still write a row, with a quantity of zero.
            $stock = new WarehouseStock;
            $stock->warehouse_id = $warehouseId;
            $stock->stock_item_id = $stockItemId;
            $stock->quantity = $quantity;
            $stock->unit = $unit;
            $stock->save();

            return $stock;
        }

        $this->guardUnit($stock, $unit);

        $stock->quantity = bcadd((string) $stock->quantity, $quantity, 3);
        $stock->save();

        return $stock;
    }

    private function openBatch(
        int $warehouseId,
        int $stockItemId,
        string $quantity,
        string $unitCost,
        PricingUnit $unit,
        StockBatchSourceType $sourceType,
        ?int $stockArrivalItemId,
        Carbon $receivedAt,
        ?int $stockMovementId = null,
        ?int $investorDealId = null,
        ?string $printingSalePrice = null,
    ): void {
        $batch = new StockBatch;
        $batch->warehouse_id = $warehouseId;
        $batch->stock_item_id = $stockItemId;
        $batch->source_type = $sourceType;
        $batch->stock_arrival_item_id = $stockArrivalItemId;
        $batch->stock_movement_id = $stockMovementId;
        // Null on almost every layer, and null means the company paid for this stock. One of the
        // three places this column is ever written — see the migration for the other two and for
        // why a missed copy is silent.
        $batch->investor_deal_id = $investorDealId;
        // Never set without a deal — the database refuses it, because a price with nobody to pay
        // it creates a margin that belongs to no one.
        $batch->printing_sale_price = $investorDealId === null ? null : $printingSalePrice;
        $batch->unit_cost = $unitCost;
        $batch->quantity_received = $quantity;
        $batch->quantity_remaining = $quantity;
        $batch->unit = $unit;
        $batch->received_at = $receivedAt;
        $batch->save();
    }

    /**
     * A product's `pricing_unit` is not expected to change once stock exists in it, but if it
     * ever does, this is what stops the balance from silently mixing two units instead of
     * reporting a number nobody could reconcile.
     *
     * @throws UnitOfMeasurementMismatch
     */
    private function guardUnit(WarehouseStock $stock, PricingUnit $unit): void
    {
        if ($stock->unit !== $unit) {
            throw UnitOfMeasurementMismatch::make($stock->unit, $unit);
        }
    }

    /**
     * The balance row for this size in this warehouse, locked until the transaction ends.
     */
    private function lockedRow(int $warehouseId, int $stockItemId): ?WarehouseStock
    {
        return WarehouseStock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('stock_item_id', $stockItemId)
            ->lockForUpdate()
            ->first();
    }
}
