<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Inventory\DTOs\StockMovementData;
use App\Domain\Inventory\InventoryService;
use App\Domain\Order\Enums\ManufacturingCostType;
use App\Domain\Order\Events\OrderScrapDrawn;
use App\Domain\Order\Exceptions\OrderNotYetInProduction;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\ProductionCostEntry;
use App\Domain\Order\Support\MaterialCost;
use Illuminate\Support\Facades\DB;

/**
 * Records bags spoiled during production — a misprint, a run gone wrong — as both a stock loss
 * and a cost, in one write.
 *
 * **Draws from the same warehouse the order's own fulfillment did.** Scrap is a real thing that
 * happens *while producing a specific order's line*, not a general stocktake correction, so it
 * belongs on the order's own cost ledger rather than a bare inventory adjustment — the same
 * reasoning `MovementType::ScrapLoss` docblock gives for not reusing `Adjustment`. That is also
 * why this can only be recorded once the order has actually reached printing: before then there
 * is no `fulfillment_warehouse_id` to draw from and nothing has been produced yet to spoil.
 *
 * **The cost is read back from the FIFO draw, never typed.** The same trick
 * {@see DeductOrderStock} uses: `InventoryService::recordMovement()` FIFO-consumes cost layers
 * behind the scenes, and `stock_batch_consumptions` rows tied to the movement it returns are the
 * only record of what that quantity actually cost.
 *
 * **And priced the same way that draw would have been priced on the line itself.** Bags spoiled
 * off a deal that sells to the press at سعر السادة cost the press that price, not what the goods
 * cost the investor — «هي من لما تكون جاهزة وبينخصم من المخزون خلاص اعطيه حقاته», the owner,
 * 2026-09-06. Anything else would make spoiling a kilo cheaper than buying it, and would quietly
 * take an investor's goods for nothing. {@see MaterialCost} is the one place that decision is
 * made, shared with the deduction and the restatement; {@see OrderScrapDrawn} is what tells
 * Investment to pay for it.
 *
 * **Deliberately does not touch `order_items.material_cost`/`cogs`.** Those remain the record of
 * what the line's *original* fulfillment cost; scrap is a separate loss, visible on its own in
 * `production_cost_entries` (`cost_type = ScrapLoss`) rather than folded back into what was
 * produced and sold.
 */
final class RecordScrapLoss
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * @throws OrderNotYetInProduction
     */
    public function __invoke(Order $order, OrderItem $item, string $quantity, string $notes, int $employeeId): ProductionCostEntry
    {
        if ($order->fulfillment_warehouse_id === null) {
            throw OrderNotYetInProduction::make();
        }

        // A line names a size; the shelf it spoiled off is a stock item, and only Inventory maps
        // one to the other. Outside the transaction because it reads and can throw.
        $item->loadMissing(['variant.stockItem', 'product.productCategory.parent']);

        $stockItem = $this->inventory->stockItemFor($item->variant);

        return DB::transaction(function () use ($order, $item, $stockItem, $quantity, $notes, $employeeId): ProductionCostEntry {
            $movement = $this->inventory->recordMovement(StockMovementData::scrapLoss(
                stockItemId: (int) $stockItem->getKey(),
                warehouseId: (int) $order->fulfillment_warehouse_id,
                quantity: $quantity,
                orderId: $order->getKey(),
                employeeId: $employeeId,
                notes: $notes,
            ));

            $cost = MaterialCost::forDraws(
                $this->inventory->consumptionBreakdownFor([(int) $movement->getKey()])[(int) $movement->getKey()] ?? [],
                $item->isPrinted(),
            );

            $entry = new ProductionCostEntry;
            $entry->order_id = $order->getKey();
            $entry->order_item_id = $item->getKey();
            $entry->cost_type = ManufacturingCostType::ScrapLoss;
            // Rate-less, like every scrap entry — see ManufacturingCostType::isRateDriven().
            $entry->quantity = $movement->quantity;
            $entry->rate = null;
            $entry->amount = $cost->charged;
            $entry->recorded_by = $employeeId;
            $entry->incurred_at = now();
            $entry->notes = $notes;
            $entry->save();

            // Announced inside the transaction the loss was written in, so the spoilage and the
            // payment for it either both stand or neither does — the discipline
            // `OrderProfitFinalised` documents at length.
            if ($cost->purchased) {
                OrderScrapDrawn::dispatch((int) $order->getKey(), (int) $movement->getKey());
            }

            return $entry;
        });
    }
}
