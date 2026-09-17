<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Actions;

use App\Domain\Customer\Actions\SyncCustomerShops;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Investor\InvestorService;
use App\Domain\PurchaseOrder\DTOs\PurchaseOrderAdditionalCostData;
use App\Domain\PurchaseOrder\DTOs\PurchaseOrderData;
use App\Domain\PurchaseOrder\DTOs\PurchaseOrderItemData;
use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrder\Exceptions\PurchaseOrderAdditionalCostDoesNotBelongToOrder;
use App\Domain\PurchaseOrder\Exceptions\PurchaseOrderIsFunded;
use App\Domain\PurchaseOrder\Exceptions\PurchaseOrderItemDoesNotBelongToOrder;
use App\Domain\PurchaseOrder\Exceptions\PurchaseOrderNotEditable;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\PurchaseOrder\Models\PurchaseOrderAdditionalCost;
use App\Domain\PurchaseOrder\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a purchase order's fields, lines and additional costs with what was sent — only while
 * it is still {@see PurchaseOrderStatus::New}.
 *
 * Lines and additional costs are each synced the same way {@see SyncCustomerShops} syncs a
 * customer's shops: an entry carrying an `id` updates that row, one without creates a new row,
 * and any existing row missing from the set is removed. Safe here specifically because nothing
 * may have shipped against a `new` order yet — there is no `quantity_received` on any line to
 * lose by deleting it.
 *
 * Every line's `unit` is recomputed the same way {@see CreatePurchaseOrder} computes it — never
 * trusted from the request. Every cost-shaped figure a line carries is then rebuilt from scratch
 * by {@see AllocatePurchaseOrderAdditionalCosts}, and `total_amount`/`total_additional_cost`
 * re-derived via {@see RecalculatePurchaseOrderTotal}.
 *
 * **And not at all once investors have funded it.** A funded order is still `new` until the
 * lorry arrives — the very state that keeps it editable — but the deal standing on it worked out
 * what fraction of the goods the partners bought from these lines' cost, showed it to them and
 * froze it. Retyping a cost underneath that would move the denominator of a frozen percent. The
 * lines and the money were agreed together; changing either alone is a new deal.
 *
 * @throws PurchaseOrderNotEditable
 * @throws PurchaseOrderIsFunded
 * @throws PurchaseOrderItemDoesNotBelongToOrder
 * @throws PurchaseOrderAdditionalCostDoesNotBelongToOrder
 */
final class UpdatePurchaseOrder
{
    public function __construct(
        private readonly AllocatePurchaseOrderAdditionalCosts $allocateAdditionalCosts,
        private readonly RecalculatePurchaseOrderTotal $recalculateTotal,
        private readonly InvestorService $investors,
    ) {}

    public function __invoke(PurchaseOrder $order, PurchaseOrderData $data): PurchaseOrder
    {
        if (! $order->status->isEditable()) {
            throw PurchaseOrderNotEditable::make($order->status);
        }

        $deals = $this->investors->fundingForPurchaseOrder((int) $order->getKey());

        if ($deals !== []) {
            throw PurchaseOrderIsFunded::make(array_map(fn (array $deal): string => $deal['code'], $deals));
        }

        return DB::transaction(function () use ($order, $data): PurchaseOrder {
            // One save rather than two: fillable and non-fillable fields are combined before
            // the single save() call below, so the whole edit lands as one UPDATE and one audit
            // log entry, not two.
            $order->fill([
                'order_date' => $data->orderDate,
                'expected_date' => $data->expectedDate,
                'notes' => $data->notes,
            ]);
            $order->vendor_id = $data->vendorId;
            $order->warehouse_id = $data->warehouseId;
            $order->save();

            $this->syncItems($order, $data->items);
            $this->syncAdditionalCosts($order, $data->additionalCosts);
            ($this->allocateAdditionalCosts)($order);
            ($this->recalculateTotal)($order);

            return $order->load(['vendor', 'warehouse', 'items.stockItem', 'additionalCosts']);
        });
    }

    /**
     * @param  list<PurchaseOrderItemData>  $items
     */
    private function syncItems(PurchaseOrder $order, array $items): void
    {
        $keptIds = [];

        // One query for every shelf on the order rather than one per line: each needs its own
        // `unit` for the line's snapshot.
        $stockItems = StockItem::query()
            ->whereIn('id', array_map(fn (PurchaseOrderItemData $item) => $item->stockItemId, $items))
            ->get()
            ->keyBy(fn (StockItem $stockItem) => $stockItem->getKey());

        foreach ($items as $item) {
            /** @var StockItem $stockItem */
            $stockItem = $stockItems->get($item->stockItemId);

            if ($item->id !== null) {
                // Scoped through the relation, so another order's line is never found here.
                $existing = $order->items()->whereKey($item->id)->first();

                if ($existing === null) {
                    throw PurchaseOrderItemDoesNotBelongToOrder::make($item->id, (int) $order->getKey());
                }

                $existing->fill(['quantity_ordered' => $item->quantityOrdered, 'base_total_cost' => $item->baseTotalCost]);
                $existing->stock_item_id = $item->stockItemId;
                $existing->forceFill(['unit' => $stockItem->unit]);
                $existing->save();
                $keptIds[] = $existing->getKey();

                continue;
            }

            // No id supplied: within one order a stock item may appear on only one line, so it
            // *is* the natural key — the same move `SyncProductVariants` makes with a variant's
            // label, and for the same reason. It lets the owner send the whole order back
            // without tracking internal line ids.
            //
            // **Not optional since `purchase_order_items_one_line_per_item` landed.** This method
            // creates before it deletes, so blindly inserting would put a second active row for
            // this (order, item) alongside the one about to be removed, and the partial unique
            // index refuses it — a 500 on a perfectly ordinary save. It used to "work" only
            // because the duplicate was soft-deleted moments later, which also threw away the
            // line's identity and its audit continuity on every single update.
            $existing = $order->items()->where('stock_item_id', $item->stockItemId)->first();

            if ($existing !== null) {
                $existing->fill(['quantity_ordered' => $item->quantityOrdered, 'base_total_cost' => $item->baseTotalCost]);
                $existing->forceFill(['unit' => $stockItem->unit]);
                $existing->save();
                $keptIds[] = $existing->getKey();

                continue;
            }

            $created = new PurchaseOrderItem(['quantity_ordered' => $item->quantityOrdered, 'base_total_cost' => $item->baseTotalCost]);
            $created->purchase_order_id = $order->id;
            $created->stock_item_id = $item->stockItemId;
            $created->quantity_received = '0.000';
            $created->forceFill(['unit' => $stockItem->unit]);
            $created->save();

            $keptIds[] = $created->getKey();
        }

        // One line at a time rather than a mass delete on the relation: a mass delete fires no
        // model events, which would leave the audit trail with nothing to say a line ever
        // existed — the same reasoning SyncCustomerShops documents. A purchase order has a
        // handful of lines.
        $order->items()
            ->whereKeyNot($keptIds)
            ->each(fn (PurchaseOrderItem $item) => $item->delete());
    }

    /**
     * @param  list<PurchaseOrderAdditionalCostData>  $additionalCosts
     *
     * @throws PurchaseOrderAdditionalCostDoesNotBelongToOrder
     */
    private function syncAdditionalCosts(PurchaseOrder $order, array $additionalCosts): void
    {
        $keptIds = [];

        foreach ($additionalCosts as $cost) {
            if ($cost->id !== null) {
                // Scoped through the relation, so another order's cost is never found here.
                $existing = $order->additionalCosts()->whereKey($cost->id)->first();

                if ($existing === null) {
                    throw PurchaseOrderAdditionalCostDoesNotBelongToOrder::make($cost->id, (int) $order->getKey());
                }

                $existing->update(['name' => $cost->name, 'amount' => $cost->amount]);
                $keptIds[] = $existing->getKey();

                continue;
            }

            $keptIds[] = $order->additionalCosts()->create(['name' => $cost->name, 'amount' => $cost->amount])->getKey();
        }

        // One row at a time rather than a mass delete on the relation — see syncItems() above
        // for why: a mass delete fires no model events, leaving the audit trail with nothing to
        // say a cost ever existed.
        $order->additionalCosts()
            ->whereKeyNot($keptIds)
            ->each(fn (PurchaseOrderAdditionalCost $cost) => $cost->delete());
    }
}
