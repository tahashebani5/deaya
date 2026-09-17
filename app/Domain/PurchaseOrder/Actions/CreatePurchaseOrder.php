<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Actions;

use App\Domain\Inventory\Models\StockItem;
use App\Domain\PurchaseOrder\DTOs\PurchaseOrderData;
use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\PurchaseOrder\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Drafts a purchase order: the document, then every line and additional cost it was raised with,
 * in one transaction.
 *
 * Always lands in {@see PurchaseOrderStatus::New} — nothing is sent to a vendor and no stock moves
 * until {@see SendPurchaseOrder} or {@see ReceivePurchaseOrder} says otherwise.
 *
 * A line's `unit` is computed here, not trusted from the request: a snapshot of the stock item's
 * own `unit` at the moment the line is written — see the docblocks on
 * {@see PurchaseOrderItem::casts()}. It used to be reached through a product; a line names the
 * shelf directly now, so the unit it will land in has one source and the hop is gone. Every cost-shaped figure a line carries — `base_unit_cost`,
 * `allocated_additional_cost`, `final_unit_cost`, `final_total_cost` — is left to
 * {@see AllocatePurchaseOrderAdditionalCosts}, run once every line and additional cost is
 * persisted, so it always sees the order's whole picture rather than one line at a time.
 * `total_amount`/`total_additional_cost` are then derived via
 * {@see RecalculatePurchaseOrderTotal}, the one place that sums a purchase order's cost.
 */
final class CreatePurchaseOrder
{
    public function __construct(
        private readonly AllocatePurchaseOrderAdditionalCosts $allocateAdditionalCosts,
        private readonly RecalculatePurchaseOrderTotal $recalculateTotal,
    ) {}

    public function __invoke(PurchaseOrderData $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data): PurchaseOrder {
            $order = new PurchaseOrder([
                'order_date' => $data->orderDate,
                'expected_date' => $data->expectedDate,
                'notes' => $data->notes,
            ]);

            // Assigned rather than mass-assigned, deliberately — see the class docblock on
            // PurchaseOrder. Status is never taken from the payload; a new order is always new.
            $order->vendor_id = $data->vendorId;
            $order->warehouse_id = $data->warehouseId;
            $order->status = PurchaseOrderStatus::New;
            $order->save();

            // One query for every shelf on the order rather than one per line: each needs its
            // own `unit` for the line's snapshot.
            $stockItems = StockItem::query()
                ->whereIn('id', array_map(fn ($item) => $item->stockItemId, $data->items))
                ->get()
                ->keyBy(fn (StockItem $stockItem) => $stockItem->getKey());

            foreach ($data->items as $item) {
                /** @var StockItem $stockItem */
                $stockItem = $stockItems->get($item->stockItemId);

                $orderItem = new PurchaseOrderItem([
                    'quantity_ordered' => $item->quantityOrdered,
                    'base_total_cost' => $item->baseTotalCost,
                ]);
                $orderItem->purchase_order_id = $order->id;
                $orderItem->stock_item_id = $item->stockItemId;
                $orderItem->quantity_received = '0.000';

                // Not fillable — see PurchaseOrderItem's docblock. Computed here, always, so a
                // request can never post a unit the shelf disagrees with.
                $orderItem->forceFill(['unit' => $stockItem->unit]);

                $orderItem->save();
            }

            foreach ($data->additionalCosts as $cost) {
                $order->additionalCosts()->create(['name' => $cost->name, 'amount' => $cost->amount]);
            }

            ($this->allocateAdditionalCosts)($order);
            ($this->recalculateTotal)($order);

            return $order->load(['vendor', 'warehouse', 'items.stockItem', 'additionalCosts']);
        });
    }
}
