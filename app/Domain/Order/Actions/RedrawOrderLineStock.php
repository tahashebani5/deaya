<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Inventory\Actions\CreditBackStockBatches;
use App\Domain\Inventory\DTOs\StockMovementData;
use App\Domain\Inventory\InventoryService;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Support\MaterialCost;

/**
 * Puts one line's draw back on the shelf and takes a different quantity instead — the single
 * mechanism behind every *partial* stock return in this application.
 *
 * **Extracted from {@see RestateOrderStockDeduction}, not written for this.** That class has done
 * exactly this since the press was first asked to confirm what a run actually consumed; when
 * partial delivery needed to put a customer's untaken bags back, the choice was to copy those
 * twenty lines or to give them a second caller. Copying them would have made two FIFO-unwinding
 * paths, which is one more than this codebase has ever had.
 *
 * **Why reverse-and-redraw rather than a delta movement.** {@see CreditBackStockBatches} credits
 * a movement back **in its entirety** — it sums that movement's `stock_batch_consumptions` and
 * returns them to their batches. There is no partial credit anywhere in Inventory, and inventing
 * one means new FIFO-unwinding logic underneath the one number nobody can afford to have drift.
 * Three further reasons, all of which hold for both callers:
 *
 * - It works identically in both directions. More used than pulled, or less — and a partial
 *   delivery is always *less* — the same two steps, no sign branching in the stock layer at all.
 * - The corrected quantity lands on the **exact original cost layers**. They are credited back
 *   before the re-draw, inside the one transaction the move already runs in, so the batches are
 *   there to be drawn from again and `material_cost` comes out right rather than averaged.
 * - `order_items.fulfillment_stock_movement_id` stays **singular**, pointing at the new movement.
 *   That is what lets {@see ReverseOrderStockDeduction} and the entire cancellation path stay
 *   exactly as they are: a delta movement would have left that column naming one of two.
 *
 * **It does not decide anything.** Which lines move, what the new figure is, and what the
 * warehouse column should read afterwards are all the caller's: a restatement corrects
 * `warehouse_quantity` to what the press actually used, while a partial delivery reduces the
 * draw to what the customer actually took. This class is handed a line and a quantity in the
 * shelf's own unit and does the two movements. Both callers then re-cost the line through the
 * same {@see MaterialCost}.
 *
 * **The credit-back deliberately does not hand priced layers to the company.** A line that bought
 * its plain material off a deal is *un-buying* it here, not writing off a sale — the goods are on
 * the shelf again, the investor's deal owns them again, and the fresh draw below buys back only
 * what is still going out. `ReverseOrderStockDeduction` passes the opposite flag for the opposite
 * situation, and the difference between the two is the whole of «استلم الزبون ما استلمش».
 */
final class RedrawOrderLineStock
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly RecalculateOrderItemCost $recalculateItemCost,
    ) {}

    /**
     * @param  string  $quantity  what should now leave the shelf, in the **stock** unit — see
     *                            {@see OrderItem::stockUnit()}. Zero is legal and means the line
     *                            takes nothing: the whole draw goes back and no fresh one is made.
     */
    public function __invoke(Order $order, OrderItem $item, string $quantity, int $employeeId): void
    {
        $stockItem = $this->inventory->stockItemFor($item->variant);

        $warehouseId = (int) $order->fulfillment_warehouse_id;

        // Recorded as an OrderReversal rather than an Adjustment for the reason
        // ReverseOrderStockDeduction gives: this is a system correction with a cause the ledger
        // can name, not an operator's stocktake.
        //
        // A line with no movement to reverse is one that never drew — a وسيط line, or one added
        // after the deduction. There is nothing to credit, and the draw below still stands.
        if ($item->fulfillment_stock_movement_id !== null) {
            $this->inventory->recordMovement(StockMovementData::orderReversal(
                stockItemId: (int) $stockItem->getKey(),
                warehouseId: $warehouseId,
                quantity: $item->producedQuantity(),
                reversedMovementId: $item->fulfillment_stock_movement_id,
                referenceId: (int) $order->getKey(),
                employeeId: $employeeId,
            ));
        }

        // Written before the re-draw, because `producedQuantity()` is what the movement below
        // takes off the shelf and it reads this column.
        $item->forceFill(['warehouse_quantity' => $quantity])->save();

        // **Nothing leaves, so nothing is drawn.** A customer who took none of a line leaves it
        // costing nothing, and a zero-quantity movement is a row in the ledger that records an
        // event which did not happen. The columns below still have to be written: they carry the
        // *old* draw's cost until something says otherwise, and that draw is back on the shelf.
        if (bccomp($quantity, '0', 3) <= 0) {
            $item->forceFill([
                'material_cost' => null,
                'material_cost_actual' => null,
                'stock_purchased_at' => null,
                'fulfillment_stock_movement_id' => null,
            ])->save();

            ($this->recalculateItemCost)($item);

            return;
        }

        $movement = $this->inventory->recordMovement(StockMovementData::fulfillment([
            'stock_item_id' => $stockItem->getKey(),
            'from_warehouse_id' => $warehouseId,
            'quantity' => $item->producedQuantity(),
            'reference_id' => $order->getKey(),
        ], $employeeId));

        // Derived exactly as the original deduction derived it — see {@see MaterialCost}.
        $cost = MaterialCost::forDraws(
            $this->inventory->consumptionBreakdownFor([(int) $movement->getKey()])[(int) $movement->getKey()] ?? [],
            $item->isPrinted(),
        );

        // The pointer moves with it. Cancelling this order later credits back the *corrected*
        // draw against the batches it actually came from. Investment follows the same pointer:
        // the purchase it booked against the old movement is reversed and rebooked against this
        // one, because that movement is no longer any line's.
        $item->forceFill([
            'material_cost' => $cost->charged,
            'material_cost_actual' => $cost->actual,
            'stock_purchased_at' => $cost->purchased ? now() : null,
            'fulfillment_stock_movement_id' => $movement->getKey(),
        ])->save();

        ($this->recalculateItemCost)($item);
    }
}
