<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Inventory\Actions\CreditBackStockBatches;
use App\Domain\Inventory\DTOs\StockMovementData;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\InventoryService;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\ProductionCostEntry;

/**
 * Undoes what {@see DeductOrderStock} did, for an order that is being cancelled after its stock
 * already left the warehouse.
 *
 * **Credits back the exact batches each line drew from — never a fresh one at an averaged
 * cost.** {@see CreditBackStockBatches} does the crediting;
 * `OrderItem::fulfillment_stock_movement_id` is what tells this action which movement's
 * `stock_batch_consumptions` to read back per line. Recorded as its own
 * {@see MovementType::OrderReversal}, not an `Adjustment` — this is a
 * system-generated correction with a cause the ledger can name, not an operator's stocktake.
 *
 * **Every production-cost entry a line carries is voided by a further entry pointing back at it,
 * never edited or deleted** — the same rule `order_payments` and `production_cost_entries`
 * themselves already follow.
 *
 * **`order_items.material_cost`/`labor_cost`/`overhead_cost`/`cogs` and `orders.total_cogs` are
 * left untouched.** They are the historical record of what production actually cost before the
 * order was written off — a fact about the day the work was done, the same treatment
 * `order_items.warehouse_quantity` and `stock_arrival_items.unit_cost` already get. The reversal
 * is a separate accounting event, not a rewrite of what happened.
 *
 * Only lines that reached printing have anything to undo — a line with no
 * `fulfillment_stock_movement_id` never took stock out in the first place.
 */
final class ReverseOrderStockDeduction
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function __invoke(Order $order, int $employeeId): void
    {
        // One query for the whole order rather than one per line; strict-mode lazy loading is on
        // outside production, so the hop from a line to its shelf has to be asked for explicitly.
        $order->items->loadMissing('variant.stockItem');

        foreach ($order->items as $item) {
            if ($item->fulfillment_stock_movement_id === null) {
                continue;
            }

            // Resolved rather than read off the line: a line names a size, a warehouse holds a
            // shelf, and only Inventory maps one to the other. A line that got this far has a
            // shelf by construction — it could not have been fulfilled otherwise — so the throw
            // inside is unreachable here rather than merely unlikely.
            $stockItem = $this->inventory->stockItemFor($item->variant);

            $this->inventory->recordMovement(StockMovementData::orderReversal(
                stockItemId: (int) $stockItem->getKey(),
                warehouseId: (int) $order->fulfillment_warehouse_id,
                quantity: $item->producedQuantity(),
                reversedMovementId: $item->fulfillment_stock_movement_id,
                referenceId: $order->getKey(),
                employeeId: $employeeId,
                // **The half of «استلم الزبون ما استلمش، المطبعة تتحمّل» that lives in the
                // warehouse.** A line that bought its plain material off a deal paid for it the
                // day it left the shelf, and that money is in an investor's ledger already.
                // Crediting the goods back to his cost layers would leave him holding both, and
                // the next order would buy the same kilo from him a second time — so those
                // layers come back as the company's own stock, at what the company paid for
                // them. Everything else on the line credits back exactly as before.
                purchasedLayersBelongToTheCompany: $item->stock_purchased_at !== null,
            ));

            $this->reverseProductionCostEntries($item, $employeeId);
        }
    }

    /**
     * Every entry this line still carries that is neither a reversal itself nor already
     * undone — see `RecalculateOrderItemManufacturingCost::activeEntriesFor()`, the same query.
     *
     * Scoped to per-line entries: nothing today ever writes an order-level one
     * (`order_item_id` null) — that shape is reserved for shared overhead allocation, which is
     * not built yet — so there is nothing of that kind to reverse.
     */
    private function reverseProductionCostEntries(OrderItem $item, int $employeeId): void
    {
        $active = ProductionCostEntry::query()
            ->where('order_item_id', $item->getKey())
            ->whereNull('reverses_entry_id')
            ->whereDoesntHave('reversal')
            ->get();

        foreach ($active as $entry) {
            $reversal = new ProductionCostEntry;
            $reversal->order_id = $entry->order_id;
            $reversal->order_item_id = $entry->order_item_id;
            $reversal->cost_type = $entry->cost_type;
            $reversal->quantity = $entry->quantity;
            $reversal->rate = $entry->rate;
            $reversal->amount = $entry->amount;
            $reversal->recorded_by = $employeeId;
            $reversal->incurred_at = now();
            $reversal->reverses_entry_id = $entry->getKey();
            $reversal->save();
        }
    }
}
