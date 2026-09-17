<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\DTOs\StockMovementData;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Enums\StockBatchSourceType;
use App\Domain\Inventory\Exceptions\FractionalQuantityNotAllowed;
use App\Domain\Inventory\Exceptions\TransferRequiresTwoDifferentWarehouses;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Queries\FindStockItem;
use Illuminate\Support\Facades\DB;

/**
 * The single write path for stock. Every quantity — and, since batch costing landed, every cost —
 * in the business changed because this ran.
 *
 * One class for all movement types, because they differ only in which ends are filled — and that
 * difference is already stated on {@see MovementType}. Several near-identical actions would be
 * several places for the balance-and-ledger pairing to drift apart, and the pairing is the entire
 * point.
 *
 * Everything happens in one transaction: the balances move, the batches behind them move with
 * them, and the row explaining all of it is written together, or none of it is. A partial failure
 * here would leave a shelf count with no reason behind it, which is precisely the state this
 * design exists to make impossible.
 *
 * **No longer asks Catalog anything.** It used to resolve a `ProductVariant` and reach through it
 * for `product->stock_unit`, because stock was keyed on a product's size. Stock is keyed on a
 * {@see StockItem} now, and the item carries its own unit — so the one question this action had
 * for another context has an answer at home. Inventory may still depend on Catalog and does
 * elsewhere; it simply has no reason to here.
 */
final class RecordStockMovement
{
    public function __construct(
        private readonly ApplyStockChange $applyStockChange,
        private readonly FindStockItem $findStockItem,
    ) {}

    public function __invoke(StockMovementData $data): StockMovement
    {
        // Outside the transaction: it can 404, and holding a lock open while it does buys
        // nothing. Resolved once and reused for both the whole-quantity guard and the unit every
        // balance touched by this movement is checked or stamped against.
        $item = ($this->findStockItem)($data->stockItemId);
        $this->guardWholeQuantity($data, $item);

        if ($data->fromWarehouseId !== null && $data->fromWarehouseId === $data->toWarehouseId) {
            throw TransferRequiresTwoDifferentWarehouses::make();
        }

        return DB::transaction(function () use ($data, $item): StockMovement {
            $movement = new StockMovement([
                'quantity' => $data->quantity,
                'notes' => $data->notes,
            ]);

            // Assigned rather than mass-assigned, deliberately. These four are the movement's
            // identity — which item, which direction, whose hands — and none of them may ever
            // be settable by a payload. See RULES.md §9.4.
            $movement->stock_item_id = $data->stockItemId;
            $movement->movement_type = $data->movementType;
            $movement->from_warehouse_id = $data->fromWarehouseId;
            $movement->to_warehouse_id = $data->toWarehouseId;
            $movement->reference_id = $data->referenceId;
            $movement->employee_id = $data->employeeId;

            // **The two that used to be carried and never written down.** `reversedMovementId`
            // has always steered `creditBack()` and then vanished, so afterwards nothing said a
            // movement had been reversed and `SUM(stock_batch_consumptions)` counted a cancelled
            // sale forever. The partial UNIQUE behind this column turns a second credit-back of
            // the same movement into a database error rather than a silently doubled shelf.
            $movement->reverses_movement_id = $data->reversedMovementId;
            $movement->adjustment_reason = $data->adjustmentReason;

            // Saved before the balances move — not after, as before batch costing landed —
            // because consuming a cost layer needs this row's own id to attach the
            // stock_batch_consumptions it produces to. Still one transaction: a balance failure
            // rolls this insert back with everything else, so the "refused movement leaves no
            // trace at all" test holds exactly as before.
            $movement->save();

            $this->moveBalances($movement->id, $data, $item->unit);

            return $movement->load(['stockItem', 'fromWarehouse', 'toWarehouse', 'employee']);
        });
    }

    /**
     * Applies both ends of this movement.
     *
     * Only {@see MovementType::InternalTransfer} ever fills both — every other type has exactly
     * one end non-null, so the deadlock-avoidance dance below applies to transfers alone.
     */
    private function moveBalances(int $movementId, StockMovementData $data, PricingUnit $unit): void
    {
        if ($data->fromWarehouseId !== null && $data->toWarehouseId !== null) {
            $this->moveTransferBalances($movementId, $data, $unit);

            return;
        }

        if ($data->fromWarehouseId !== null && $data->movementType === MovementType::ArrivalReversal) {
            // Deliberately ahead of the generic decrease below: this one knows *which* layers to
            // take back — the ones the arrival opened — and must never be allowed to fall through
            // to a FIFO draw on the oldest stock in the building.
            $this->applyStockChange->withdrawArrival(
                $data->fromWarehouseId, $data->stockItemId, $unit,
                $data->reversedMovementId, $movementId,
            );

            return;
        }

        if ($data->fromWarehouseId !== null) {
            $this->applyStockChange->decrease(
                $data->fromWarehouseId, $data->stockItemId, $data->quantity, $unit, $movementId,
            );

            return;
        }

        if ($data->toWarehouseId !== null && $data->movementType === MovementType::OrderReversal) {
            $this->applyStockChange->creditBack(
                $data->toWarehouseId, $data->stockItemId, $unit, $data->reversedMovementId,
                $data->purchasedLayersBelongToTheCompany,
            );

            return;
        }

        if ($data->toWarehouseId !== null) {
            $this->applyStockChange->increase(
                $data->toWarehouseId, $data->stockItemId, $data->quantity, $unit,
                $this->batchCost($data), $this->batchSource($data->movementType),
                // No arrival line to point at — see the stock_batches migration for why that
                // column is still never filled. The movement id is the traceability that *is*
                // available at this moment, and it reaches the same document by one more hop.
                stockArrivalItemId: null,
                stockMovementId: $movementId,
                // Resolved by Investment before the goods arrived, and null for everything the
                // company bought for itself — which is almost all of it.
                investorDealId: $data->investorDealId,
                // And, beside it, the price that deal sells to the press at — a term agreed
                // while the lorry was still being funded.
                printingSalePrice: $data->printingSalePrice,
            );
        }
    }

    /**
     * The one case with two rows to lock: relocates the stock, and the cost layers it is made of,
     * from one warehouse to another.
     *
     * Two transfers running at once in opposite directions between the same pair of warehouses
     * would, taken in payload order, each hold the row the other is waiting for — a deadlock
     * PostgreSQL resolves by killing one of them, which surfaces to a storekeeper as a 500 for
     * no reason they could ever act on. Locking both rows in ascending warehouse id first — before
     * either balance actually moves — gives every such transaction the same lock order, so one
     * simply waits; re-locking a row already held costs nothing, so the decrease/relocate calls
     * below can then run in the order that makes business sense (source first, so its cost layers
     * are known before the destination recreates them) regardless of which id is smaller.
     */
    private function moveTransferBalances(int $movementId, StockMovementData $data, PricingUnit $unit): void
    {
        $ids = [$data->fromWarehouseId, $data->toWarehouseId];
        sort($ids);

        foreach ($ids as $warehouseId) {
            $this->applyStockChange->lockBalance($warehouseId, $data->stockItemId);
        }

        $result = $this->applyStockChange->decrease(
            $data->fromWarehouseId, $data->stockItemId, $data->quantity, $unit, $movementId,
        );

        $this->applyStockChange->relocateBatches(
            $data->toWarehouseId, $data->stockItemId, $unit, $result->consumed,
        );
    }

    /**
     * What a brand-new cost layer opens at. `null` — no cost was ever supplied for this
     * movement — becomes `'0.000'`, the same "unknown" placeholder the opening-balance backfill
     * uses; see {@see ApplyStockChange::increase()}.
     */
    private function batchCost(StockMovementData $data): string
    {
        return $data->unitCost ?? '0.000';
    }

    private function batchSource(MovementType $type): StockBatchSourceType
    {
        return match ($type) {
            MovementType::PurchaseArrival => StockBatchSourceType::PurchaseArrival,
            MovementType::Adjustment => StockBatchSourceType::Adjustment,
            // Never reached: InternalTransfer, OrderReversal and ArrivalReversal each relocate,
            // credit back or withdraw existing batches instead of opening one, and
            // OrderFulfillment, ScrapLoss and an Adjustment-decrease only ever call decrease().
            MovementType::InternalTransfer, MovementType::OrderFulfillment,
            MovementType::OrderReversal, MovementType::ScrapLoss,
            MovementType::ArrivalReversal => StockBatchSourceType::Adjustment,
        };
    }

    /**
     * Half a shipping bag is a typo, half a kilo is Tuesday.
     *
     * Keyed off the stock item's own `unit` — what the shelf is counted in — and deliberately not
     * the same check `QuoteProductRequest` makes for an order quantity, which is keyed off the
     * product's `pricing_unit`. The two are allowed to disagree on purpose: a thing bought in by
     * weight and sold by the piece needs whole numbers on the way out to a customer and may still
     * take fractional amounts off a shelf.
     *
     * That divergence has not changed with this table; only its second half has an owner now. It
     * used to be `products.stock_unit`, which meant two products sharing one pile could each
     * claim a different answer for it.
     */
    private function guardWholeQuantity(StockMovementData $data, StockItem $item): void
    {
        if (! $item->unit->requiresWholeQuantities()) {
            return;
        }

        // The quantity is already normalised to three places, so "the fractional part is zero"
        // is the comparison — not a float floor(), which would answer wrongly for values a
        // storekeeper can plausibly type.
        if (bccomp($data->quantity, bcadd($data->quantity, '0', 0), 3) !== 0) {
            throw FractionalQuantityNotAllowed::make($data->quantity);
        }
    }
}
