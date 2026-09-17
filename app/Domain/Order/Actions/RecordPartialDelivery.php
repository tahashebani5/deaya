<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Order\Enums\ManufacturingCostType;
use App\Domain\Order\Enums\UndeliveredDisposition;
use App\Domain\Order\Events\OrderStockDrawn;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\ProductionCostEntry;
use App\Domain\Order\Support\TransitionFields;

/**
 * Records that the customer took only part of the order, and disposes of what they left.
 *
 * **The only writer of `undelivered_quantity` and `undelivered_disposition`**, the same
 * relationship {@see SetOrderShortages} has with `shortage_quantity` and for the same reason: the
 * number moves money — see {@see OrderItem::billableQuantity()} — so a second path that set it
 * without re-deriving the totals would leave an invoice quietly disagreeing with its own lines.
 *
 * **The item lock is not in the way, and this is not a way around it.**
 * {@see Order::itemsAreEditable()} closes at «جاهزة» and must stay closed: the bags exist and are
 * counted by then, so changing what the order says was *ordered* would make the invoice disagree
 * with the shelf. Nothing here changes what was ordered. `quantity` never moves — it is the
 * question a partial delivery is the answer to — and what is written is a new fact about a new
 * moment, exactly as `warehouse_quantity` is written onto locked lines at «جاهزة».
 *
 * **Reversible by construction, like the shortage.** Nothing is subtracted from anything: clear
 * the column and the line returns to the exact number it was, because the total is derived from
 * the ordered quantity and the price agreed on the day, neither of which this ever touches.
 *
 * ## Where the leftover goes
 *
 * Per line, never per order — {@see UndeliveredDisposition::forItem()} — because a mixed order
 * restocks its سادة lines and writes off its printed ones in the same breath:
 *
 * - **سادة** goes back on the shelf, through {@see RedrawOrderLineStock}: the whole draw is
 *   credited to its original cost layers and only what the customer took is drawn again. The
 *   line's `material_cost` falls with it, which is right — we have the goods back.
 * - **مطبوع and وسيط** become a `delivery_loss` {@see ProductionCostEntry}. Nothing moves in the
 *   warehouse: printed bags left the shelf at «جاهزة» and وسيط goods were never on one at all.
 *   The line's costs are left standing, which is also right — we made them and we ate them.
 *
 * **The loss entry does not add to COGS, deliberately.** `ManufacturingCostType::isLoss()` keeps
 * it out of `labor_cost`/`overhead_cost`, so it is reported and never subtracted. The money is
 * already in gross profit by construction: revenue fell with `billableQuantity()` while the cost
 * frozen at «جاهزة» did not. Adding the entry on top would charge those bags twice. See
 * PARTIAL-DELIVERY-DESIGN.md §3, Decision 2.
 *
 * **Runs inside the transaction {@see ChangeOrderStatus} already opened**, before the profit is
 * finalised, so the investor split sees the corrected figures and the whole move stands or falls
 * together.
 */
final class RecordPartialDelivery
{
    public function __construct(
        private readonly RedrawOrderLineStock $redraw,
        private readonly RecalculateOrderTotals $recalculateTotals,
        private readonly RecalculateOrderCogs $recalculateCogs,
    ) {}

    /**
     * @param  array<string, mixed>  $fields  What the move asked for — see {@see TransitionFields}.
     * @return bool whether anything went back on a shelf, which the caller owes
     *              {@see OrderStockDrawn} — see the note on {@see restock()}.
     */
    public function __invoke(Order $order, array $fields, int $employeeId): bool
    {
        // `product.productCategory.parent` because the disposition walks it, `variant.stockItem`
        // because the restock resolves a shelf through it. Once, here, rather than per line:
        // strict mode turns a forgotten load into an exception rather than a query each.
        $order->items->loadMissing(['variant.stockItem', 'product.productCategory.parent']);

        $recorded = false;
        $restocked = false;

        foreach ($order->items as $item) {
            $left = $this->undeliveredQuantity($item, $fields);

            if ($left === null) {
                continue;
            }

            $recorded = true;

            $disposition = UndeliveredDisposition::forItem($item);

            // Written before the disposal, because both the re-pricing below and the loss
            // entry's share read these columns back off the line.
            $item->forceFill([
                'undelivered_quantity' => $left,
                'undelivered_disposition' => $disposition,
            ]);
            $item->forceFill(['line_total' => $item->deriveLineTotal()])->save();

            if ($disposition->returnsToStock()) {
                $this->restock($order, $item, $fields, $employeeId);
                $restocked = true;
            } else {
                $this->writeOff($order, $item, $left, $employeeId);
            }
        }

        if (! $recorded) {
            return false;
        }

        // The invoice first, then the cost — `RecalculateOrderCogs` sums what the restock above
        // may have just lowered, and both must stand before `OrderProfitFinalised` is announced.
        ($this->recalculateTotals)($order->load('items'));
        ($this->recalculateCogs)($order->load('items'));

        return $restocked;
    }

    /**
     * What this line's customer left behind, or null when they took all of it.
     *
     * **The form asks what was *taken*, and this is where that becomes what was left.** The
     * person at the counter is holding the goods they handed over and counting those; making
     * them subtract to reach the remainder is arithmetic done by the wrong party. The box opens
     * pre-filled with the whole billable quantity, so the common case — they took everything —
     * comes back identical and lands here as null.
     *
     * Null too for an absent or empty box, which is the same answer said a different way, and
     * for anything that would leave nothing behind. Compared numerically rather than as strings:
     * «300» and «300.000» are the same count, and a string comparison would write a zero
     * remainder onto every line of every order ever delivered.
     *
     * @param  array<string, mixed>  $fields
     */
    private function undeliveredQuantity(OrderItem $item, array $fields): ?string
    {
        $answer = $fields[TransitionFields::deliveredQuantityKey($item)] ?? null;

        if ($answer === null || $answer === '') {
            return null;
        }

        $billable = $item->billableQuantity();

        // Clamped rather than refused: the request already caps the box at this figure, and a
        // 500 here would be the domain arguing with validation it agrees with. What it must not
        // do is let a typo write a negative remainder past the database's own CHECK.
        if (bccomp((string) $answer, $billable, 3) >= 0) {
            return null;
        }

        return bcsub($billable, (string) $answer, 3);
    }

    /**
     * Plain goods, back on the shelf.
     *
     * **Whoever sold us those bags has to be un-paid for them, and that is the caller's job.**
     * A سادة line that drew on an investor's deal bought its material at سعر السادة the day it
     * left the shelf, and his wallet was credited then. {@see RedrawOrderLineStock} credits the
     * goods back to *his* cost layers rather than to the company's — a partial delivery is
     * un-buying, not writing off — so leaving the payment standing would have him holding both
     * the money and the bags, and the next order would buy the same kilo from him a second time.
     *
     * `PostDealStockPurchases` already knows how to correct that: it is keyed on
     * `order_items.id` precisely so a redrawn line is recognised as the same source, reversed
     * and re-posted, and a line that drops out of the draw entirely is found and reversed too.
     * What it needs is to be told, which is why this method's caller returns a flag and
     * {@see ChangeOrderStatus} announces {@see OrderStockDrawn}.
     *
     * **The quantity is in the shelf's unit, and where it comes from depends on whether the two
     * units agree.** A line sold and stocked in the same unit converts exactly: what is left of
     * the draw is what is left of the sale. A line sold by the piece and stocked by the kilo has
     * no meaningful per-piece weight — {@see DeductOrderStock} refuses to multiply one out — so
     * the form asks the storekeeper directly, pre-filled with the pro-rata as a suggestion to
     * correct on the scale.
     *
     * @param  array<string, mixed>  $fields
     */
    private function restock(Order $order, OrderItem $item, array $fields, int $employeeId): void
    {
        $answer = $fields[TransitionFields::returnedQuantityKey($item)] ?? null;

        $drawn = (string) ($item->warehouse_quantity ?? $item->quantity);

        $keep = $answer === null || $answer === ''
            ? $item->deliveredStockQuantity()
            : bcsub($drawn, (string) $answer, 3);

        if (bccomp($keep, '0', 3) < 0) {
            $keep = '0.000';
        }

        // **Written before the redraw, because the redraw is what makes it unknowable.**
        // `RedrawOrderLineStock` writes `warehouse_quantity` over itself with what stays drawn,
        // so the figure that left the shelf survives nowhere on the line afterwards — only in
        // the two stock movements, a screen away and in another unit's company. Recording it
        // here is what lets the order answer «كم رجع» without leaving the order.
        $item->forceFill(['restocked_quantity' => bcsub($drawn, $keep, 3)])->save();

        ($this->redraw)($order, $item, $keep, $employeeId);
    }

    /**
     * Printed or وسيط goods: a named loss, and nothing touched in the warehouse.
     *
     * **Priced from the line's own `cogs`, not from FIFO.** {@see RecordScrapLoss} reads what
     * spoiled bags cost by consuming cost layers, because spoilage draws fresh stock off a shelf.
     * Nothing is drawn here — these goods left at «جاهزة» and were costed onto the line then — so
     * the loss is that line's share of what it already cost. That is also the only figure a وسيط
     * line has: it never had a `material_cost`, and its `cogs` is its `outsourcing_cost`.
     *
     * **Rate-less, like every loss entry** — see {@see ManufacturingCostType::isRateDriven()}.
     *
     * A line with no `cogs` writes no entry rather than an entry of zero: a line that never
     * reached «جاهزة» has no cost to take a share of, and «خسرنا صفراً» is a claim nobody made.
     * The quantity is still recorded on the line, so the invoice still falls.
     */
    private function writeOff(Order $order, OrderItem $item, string $left, int $employeeId): void
    {
        $amount = $item->deliveryLoss();

        if ($amount === null) {
            return;
        }

        $entry = new ProductionCostEntry;
        $entry->order_id = $order->getKey();
        $entry->order_item_id = $item->getKey();
        $entry->cost_type = ManufacturingCostType::DeliveryLoss;
        $entry->quantity = $left;
        $entry->rate = null;
        $entry->amount = $amount;
        $entry->recorded_by = $employeeId;
        $entry->incurred_at = now();
        $entry->notes = 'تسليم جزئي — لم يستلمه العميل';
        $entry->save();
    }
}
