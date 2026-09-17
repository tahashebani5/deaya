<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Inventory\Actions\ApplyStockChange;
use App\Domain\Inventory\DTOs\StockMovementData;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Exceptions\VariantHasNoStockItem;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Order\DTOs\StockShortfall;
use App\Domain\Order\Exceptions\LineNeedsAMeasuredStockQuantity;
use App\Domain\Order\Exceptions\OrderStockShortfall;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Support\MaterialCost;
use App\Domain\Order\Support\TransitionFields;

/**
 * Takes an order's lines out of a warehouse, once — the link between `Order` and `Inventory`.
 * Called by {@see ChangeOrderStatus}, inside its own transaction, the moment an order first
 * enters `ready`.
 *
 * **This class never writes a balance or a ledger row itself.**
 * {@see InventoryService::recordMovement()} — the same call every other stock movement in this
 * application goes through — is the only path taken, per RULES.md §3: cross-context work goes
 * through the other module's Service, never around it. One movement per line, so a shortfall on
 * line two of three throws mid-loop and rolls back everything `ChangeOrderStatus` did in this
 * call, including the status change itself and any lines already deducted — there is no partial
 * deduction sitting beside an order that still reads `new`.
 *
 * **Every line resolves to a shelf before anything moves.** A line names a product size; a
 * warehouse holds a {@see StockItem}. Two sizes on one order can be the same pile — كيس شحن سادة
 * 25*35 and كيس شحن مطبوع 25*35 are two catalogue rows and one heap of bags — and this action is
 * where that stops being invisible. A size pointing at no shelf at all is refused by name rather
 * than dereferenced; see {@see VariantHasNoStockItem}.
 *
 * **The number deducted is exactly what the employee typed, nothing more.** A line whose
 * `warehouse_quantity` is null deducts the ordered quantity unchanged — sales unit and warehouse
 * unit agree, the common case. A line that has one deducts that value as-is, not
 * `quantity * something` — bags weighed together on a scale don't have a meaningful per-piece
 * weight to multiply out, so the employee enters the batch total directly and it is trusted
 * whole. See {@see OrderItem::producedQuantity()}.
 *
 * **And a line whose two units differ is not deducted at all until somebody has measured it.**
 * That fallback to the ordered quantity is only safe where the units agree; on a line sold by
 * the piece and stocked by the kilo it would take the piece count off a kilogram shelf. The move
 * into «جاهزة» asks for the figure — see {@see TransitionFields} —
 * and {@see guardEveryLineIsMeasured()} is the floor under that form. **Which unit "the shelf's"
 * means moved with this merge**: it used to be `products.stock_unit` and is now the `unit` of the
 * {@see StockItem} the size draws on — see
 * {@see OrderItem::stockUnit()}. The question the guard asks is unchanged.
 *
 * **Since batch costing landed, this is also the one place `order_items.material_cost` is
 * written.** `InventoryService::recordMovement()` FIFO-consumes cost layers behind the scenes;
 * `stock_batch_consumptions` rows tied to the movement it returns are the only record of what
 * that actually cost, so this action reads them back rather than the movement carrying the
 * figure itself. Two lines drawing on one shelf therefore draw in order: the first takes the
 * older layer, and the two can legitimately carry different costs for the same item. That was
 * already true of two lines of one size and is not new here.
 */
final class DeductOrderStock
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly RecalculateOrderItemCost $recalculateItemCost,
    ) {}

    /**
     * @throws LineNeedsAMeasuredStockQuantity
     * @throws OrderStockShortfall
     * @throws VariantHasNoStockItem
     */
    public function __invoke(Order $order, int $warehouseId, int $employeeId): void
    {
        // **Loaded once, here, because the guard below reads it.** `OrderItem::stockUnit()` now
        // answers from the shelf rather than from the product, so asking whether a line is
        // stocked in another unit touches `variant.stockItem` — and strict mode turns a
        // forgotten load into an exception rather than a query per line.
        // `product.productCategory.parent` beside the shelf, because `OrderItem::isPrinted()`
        // walks it for every line — see the note on the pricing below.
        $order->items->loadMissing(['variant.stockItem', 'product.productCategory.parent']);

        // Before the shelves are even resolved: a line with no measurement has no figure to
        // weigh against one, and the number that would stand in for it is the wrong one.
        $this->guardEveryLineIsMeasured($order);

        // **Which pile each line comes off, resolved once.** Two sizes of two products can land
        // on one shelf, so the requirement is totalled per shelf rather than per line — see
        // {@see guardTheWarehouseHasItAll()}.
        $shelves = $this->shelvesFor($order);

        $this->guardTheWarehouseHasItAll($order, $warehouseId, $shelves);

        foreach ($order->items as $item) {
            $movement = $this->inventory->recordMovement(StockMovementData::fulfillment([
                'stock_item_id' => $shelves[$item->getKey()]->getKey(),
                'from_warehouse_id' => $warehouseId,
                'quantity' => $item->producedQuantity(),
                'reference_id' => $order->getKey(),
            ], $employeeId));

            // **What the line pays, and what the goods cost — two numbers since سعر السادة.**
            // A printed line drawing on a deal that sells to the press buys its plain bags off
            // the shelf at that price, and `material_cost` becomes what it paid; the FIFO figure
            // survives beside it as `material_cost_actual`, because a company-wide profit is
            // still computed from what the goods really cost. See {@see MaterialCost}, which both
            // this and {@see RestateOrderStockDeduction} derive it through.
            $cost = MaterialCost::forDraws(
                $this->inventory->consumptionBreakdownFor([(int) $movement->getKey()])[(int) $movement->getKey()] ?? [],
                $item->isPrinted(),
            );

            // The forward pointer ReverseOrderStockDeduction reads back if this order is later
            // cancelled — see the note on OrderItem::fulfillmentStockMovement().
            $item->forceFill([
                'material_cost' => $cost->charged,
                'material_cost_actual' => $cost->actual,
                // The fact three other places read: the investor behind this line has been paid
                // already, so the delivery must not pay him again, this line must not hold his
                // deal open, and a cancellation returns its goods to the company.
                'stock_purchased_at' => $cost->purchased ? now() : null,
                'fulfillment_stock_movement_id' => $movement->getKey(),
            ])->save();

            ($this->recalculateItemCost)($item);
        }
    }

    /**
     * Refuses an order carrying a line nobody could have deducted automatically.
     *
     * **Every such line at once, not the first one.** The same reasoning
     * {@see guardTheWarehouseHasItAll()} carries: an order with two unweighed sizes on it should
     * say so once, rather than reveal the second after the first is answered.
     *
     * @throws LineNeedsAMeasuredStockQuantity
     */
    private function guardEveryLineIsMeasured(Order $order): void
    {
        $unmeasured = $order->items
            ->filter(fn (OrderItem $item): bool => $item->warehouse_quantity === null
                && $item->isStockedInAnotherUnit())
            ->values()
            ->all();

        if ($unmeasured !== []) {
            throw LineNeedsAMeasuredStockQuantity::make($unmeasured);
        }
    }

    /**
     * The shelf behind every line, keyed by order item id, resolved in two queries for the whole
     * order rather than one per line.
     *
     * Resolved up front, before anything moves, so that an order containing one unlinked size
     * fails naming that size instead of half-deducting the lines before it.
     *
     * @return array<int, StockItem>
     *
     * @throws VariantHasNoStockItem
     */
    private function shelvesFor(Order $order): array
    {
        $order->items->loadMissing('variant.stockItem');

        $shelves = [];

        foreach ($order->items as $item) {
            $shelves[$item->getKey()] = $this->inventory->stockItemFor($item->variant);
        }

        return $shelves;
    }

    /**
     * Weighs every line against the warehouse before a single one is taken out of it.
     *
     * **This is about the message, not the safety.** {@see ApplyStockChange} already refuses a
     * movement that would overdraw a shelf, under a lock, and it stays the only thing standing
     * between two foremen printing the same stock at once — a balance read here is stale the
     * moment it is read, so nothing below is treated as permission. What the loop above cannot
     * do is *report*: it fails on the first short line with {@see InsufficientStock}, which
     * carries two numbers and no name, and never reaches the lines after it. So an order with
     * three short items said «not enough» once, about something it did not name, and revealed the
     * others one restock at a time.
     *
     * **Totalled per shelf, not per line — and that is what this change is for.** Two lines of
     * 300 and 400 that draw on the same pile each fit inside a shelf of 500 on their own; the
     * order does not. Keyed on the stock item, they add up to 700 against one balance and the
     * order is refused here, by name, instead of passing two separate checks and coming up short
     * on the floor. Before stock items existed those two lines were two different keys and there
     * was no arrangement of this loop that could have caught it.
     *
     * @param  array<int, StockItem>  $shelves
     *
     * @throws OrderStockShortfall
     */
    private function guardTheWarehouseHasItAll(Order $order, int $warehouseId, array $shelves): void
    {
        $available = $this->inventory->balancesFor(
            $warehouseId,
            array_values(array_unique(array_map(
                fn (StockItem $item) => (int) $item->getKey(),
                $shelves,
            ))),
        );

        /** @var array<int, string> $required */
        $required = [];
        /** @var array<int, StockShortfall> $shortfalls */
        $shortfalls = [];

        foreach ($order->items as $item) {
            $shelf = $shelves[$item->getKey()];
            $stockItemId = (int) $shelf->getKey();

            // Absent from the map means this item has never been in this warehouse, which is the
            // same answer as an empty shelf — and the same number the storekeeper needs either way.
            $onShelf = $available[$stockItemId] ?? '0.000';
            $required[$stockItemId] = bcadd($required[$stockItemId] ?? '0', $item->producedQuantity(), 3);

            if (bccomp($onShelf, $required[$stockItemId], 3) >= 0) {
                continue;
            }

            // Keyed by shelf rather than appended, so a second line drawing on a pile already
            // short replaces its entry with the larger total instead of naming it twice.
            //
            // Named by the shelf, not by «المنتج — المقاس»: what is short is one pile, and two
            // different products can be the reason. Naming either of them would send the
            // storekeeper looking for the wrong thing.
            $shortfalls[$stockItemId] = new StockShortfall(
                name: $shelf->displayName(),
                available: $onShelf,
                required: $required[$stockItemId],
            );
        }

        if ($shortfalls !== []) {
            throw OrderStockShortfall::make(array_values($shortfalls));
        }
    }
}
