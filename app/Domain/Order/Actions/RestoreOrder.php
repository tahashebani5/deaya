<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Audit\Concerns\CascadesSoftDeletes;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Exceptions\VariantHasNoStockItem;
use App\Domain\Order\Enums\ManufacturingCostType;
use App\Domain\Order\Events\OrderStockRedrawn;
use App\Domain\Order\Exceptions\FulfillmentRequiresAnActor;
use App\Domain\Order\Exceptions\FulfillmentWarehouseIsDeleted;
use App\Domain\Order\Exceptions\OrderIsNotDeleted;
use App\Domain\Order\Exceptions\OrderStockShortfall;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\ProductionCostEntry;
use App\Domain\Order\Support\StockEffectPreview;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Brings an archived order back — **with its stock drawn again**, which is the whole of what
 * makes it different from {@see ReinstateCancelledOrder}.
 *
 * A reinstatement undoes a *decision*: the cancellation happened, the goods really did come back
 * onto the shelf, and taking them out again would be inventing a movement. A delete claims the
 * order should never have been recorded, so bringing it back has to bring back the state it was
 * in — and it was in a state where its bags had left the warehouse. Leaving them on the shelf
 * would have the order say the goods went out while the warehouse says they are still there,
 * which is the exact defect this feature exists to prevent. See §١.
 *
 * **The price is that the cost changes, and it is said out loud rather than hidden.** The new
 * draw eats today's cost layers, not the ones the first draw ate, so the order comes back at a
 * different `total_cogs` — see {@see StockEffectPreview}, which puts that sentence in front of
 * the person before they press the button.
 *
 * **Locked `withTrashed()` as the first statement**, exactly as {@see DeleteOrder} is, and here
 * `withTrashed()` is not merely useful but required: every order this action is ever called on
 * is soft-deleted, so the ordinary scoped query would 404 on all of them.
 *
 * **Nothing preserves `stock_purchased_at` across the round trip, and that is deliberate.** The
 * stamp says what the draw the line is pointing at bought, and after a restore the line points at
 * a new draw — so it is left exactly where {@see DeductOrderStock} puts it, `null` included.
 *
 * Because of what a delete does with a priced layer: {@see CreditBackStockBatches} is called with
 * `purchasedLayersBelongToTheCompany`, so those goods do **not** go back to the deal — they are
 * re-opened as the company's own stock at the price it paid, and the investor keeps his money.
 * «استلم الزبون ما استلمش، المطبعة تتحمّل.» His sale completed. The fresh draw is a different
 * purchase, of whatever FIFO now puts in front of it, and usually of that same returned lot.
 *
 * A stamp carried over from before the delete is therefore wrong in both places the column is
 * read. {@see StockPurchaseAttributionQuery} would go on naming the line, and
 * {@see PostDealStockPurchases} — which keys a payment on the *line* and treats a second posting
 * as a correction of the first — would recompute it against a draw that reaches none of that
 * deal's layers and reverse the سعر السادة he was already paid: he loses the goods and the money
 * both. And {@see ReverseOrderStockDeduction} would hand the goods to the company on a later
 * cancellation for a line that bought nothing from anybody.
 *
 * The mirror worry — that clearing it lets a later cancellation pay an investor twice — is
 * answered by leaving the column to `DeductOrderStock` rather than clearing it here: a fresh draw
 * that *does* eat a priced layer stamps the line itself, and {@see OrderStockRedrawn} pays for
 * that draw on its own movement. What the column says is then true of the goods the line is
 * holding now, which is the only claim it was ever making. See BACKLOG.md,
 * «الاستعادة ثم إعادة بيان الخصم», for the one hazard this does not close.
 *
 * **Nothing cascades back, because nothing cascaded away.** `Order` does not carry
 * {@see CascadesSoftDeletes} at all — see §٥ and the note now closed
 * in that trait: cascading `transitions()` would blank the progress bar on every archived order,
 * because `Order::progress()` falls through to `furthestMainLineStep()` for every status outside
 * the main line and reads `transitions()` without `withTrashed()`.
 */
final class RestoreOrder
{
    public function __construct(
        private readonly DeductOrderStock $deductStock,
        private readonly ApplyManufacturingRates $applyManufacturingRates,
        private readonly RecalculateOrderCogs $recalculateCogs,
    ) {}

    /**
     * @throws OrderIsNotDeleted
     * @throws FulfillmentWarehouseIsDeleted
     * @throws FulfillmentRequiresAnActor
     * @throws OrderStockShortfall
     * @throws VariantHasNoStockItem
     */
    public function __invoke(Order $order, ?User $actor = null): Order
    {
        return DB::transaction(function () use ($order, $actor): Order {
            $locked = Order::withTrashed()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->trashed()) {
                throw OrderIsNotDeleted::make($locked->status);
            }

            $redrawn = [];

            // **Only when the delete is the one that returned the goods.** An order cancelled
            // before it was deleted had its stock credited back by the *cancellation*, and the
            // delete rightly left the shelf alone — re-deducting for it here would take 300 bags
            // off the shelf that this archive never put there.
            if ($locked->deleteReturnedStock()) {
                $redrawn = $this->drawTheStockAgain($locked, $actor);
            }

            // Cleared before the restore rather than left standing: unlike `stock_deducted_at`,
            // which remembers that stock left this order once and stays true for ever, this
            // column describes what the *archive* is holding. An order back in the lists still
            // carrying it would offer a second undo of a thing already undone — the same reason
            // {@see ReinstateCancelledOrder} clears `cancelled_at` on the way out.
            $locked->forceFill(['delete_returned_stock_at' => null])->save();

            $locked->restore();

            // **Announced after the restore, and that ordering is the whole of it.** The
            // listener is synchronous and inside this transaction, so the goods leaving and the
            // payment for them stand or fall together — but the order it is about is soft-deleted
            // until the line above, and anything reading it back through an ordinary scoped query
            // would find nothing and write nothing, with no error anywhere. That is the trap §٢٫٢
            // documents for `ApplyNawrisStatus` and §٥ for `Order::progress()`, and it is cheaper
            // to be immune to it than to audit every listener for it.
            //
            // **One event per movement, and deliberately not {@see OrderStockDrawn}** — see
            // {@see OrderStockRedrawn}: this draw stands beside the one the delete settled rather
            // than correcting it.
            foreach ($redrawn as $movementId) {
                OrderStockRedrawn::dispatch((int) $locked->getKey(), $movementId);
            }

            return $locked->refresh();
        });
    }

    /**
     * Takes the goods back out, and then makes the cost columns tell the truth about it.
     *
     * **{@see DeductOrderStock} is called rather than reimplemented, and it brings both of §٣'s
     * guards with it.** A variant whose shelf has since been unlinked is refused by name through
     * {@see VariantHasNoStockItem}, resolved for the whole order before anything moves; and a
     * shelf that is short answers with {@see OrderStockShortfall}, which names each pile and its
     * two numbers, rather than the bare `InsufficientStock` the balance itself would throw on the
     * first short line and stop.
     *
     * **{@see RecalculateOrderCogs} is called explicitly, and that is the point of this method.**
     * It has exactly one other caller in the whole application — inside `ChangeOrderStatus`,
     * behind a `ready_at === null` guard — while `DeductOrderStock` writes `order_items.cogs` and
     * never touches `orders.total_cogs`. A restore that skipped it would leave the order's total
     * on yesterday's figure while every line beneath it had moved to today's layers, and
     * `Order::grossProfit()`, the P&L and `PostDealEarningsForOrder` all read the total.
     *
     * **Nothing puts `stock_purchased_at` back where it was**, and the class docblock carries
     * the whole of why: the fresh draw is a different purchase from the one the delete settled.
     *
     * @return list<int> the movements this draw made, for the announcement the caller owes them
     *
     * @throws FulfillmentWarehouseIsDeleted
     * @throws FulfillmentRequiresAnActor
     */
    private function drawTheStockAgain(Order $order, ?User $actor): array
    {
        // Checked before the deduction and for a different symptom than the delete's: a retired
        // warehouse has no `warehouse_stocks` row to lock, so `InsufficientStock('0.000', …)`
        // would tell the storekeeper that every size reads zero when the truth is that the shelf
        // itself is gone. See §٣.
        if (! $order->fulfillmentWarehouse()->exists()) {
            throw FulfillmentWarehouseIsDeleted::make((string) $order->code);
        }

        if ($actor === null) {
            throw FulfillmentRequiresAnActor::make();
        }

        $order->loadMissing('items.product');

        // **Read before anything is written, because the answer is about the past.** Everything
        // this method may re-apply is decided by what the delete's reversal voided — see
        // {@see productionCostTheDeleteVoided()} — and the entries below are about to add rows
        // that would muddy that reading if it were taken afterwards.
        $voided = $this->productionCostTheDeleteVoided($order);

        ($this->deductStock)($order, (int) $order->fulfillment_warehouse_id, (int) $actor->getKey());

        $this->reapplyProductionCost($order, $voided, (int) $actor->getKey());

        ($this->recalculateCogs)($order);

        return $order->items
            ->map(fn (OrderItem $item): ?int => $item->fulfillment_stock_movement_id === null
                ? null
                : (int) $item->fulfillment_stock_movement_id)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The entries the delete's reversal voided — every one of them, whatever its type.
     *
     * **The ledger is asked, not `ready_at`**, and the two only look alike. `ChangeOrderStatus`
     * costs production behind `$target === Ready && $order->ready_at === null`, so reading that
     * column here would answer «has this order ever been called ready» — a near-miss for «did the
     * delete void anything», and wrong in both directions. An order that reached «جاهزة» in a
     * month when no {@see ManufacturingCostRate} was on file was costed nothing, and re-applying
     * against today's rate table would invent wages nobody ever owed; an order carrying only a
     * {@see ManufacturingCostType::ScrapLoss} — recordable from «جاهزة للطباعة», where `ready_at`
     * is still null — did have a real cost voided.
     *
     * **And under this method's one caller the ledger cannot be misread.** «Voided» is a state,
     * not an event, so on its own it would equally describe entries a *cancellation* voided — but
     * {@see ReverseOrderStockDeduction} is the only writer of a reversal in the whole application
     * and it voids a line's entries in the same breath as it credits its draw back, so an order
     * whose cancellation voided its entries has a live reversal against every line, cannot be
     * carrying `delete_returned_stock_at`, and never reaches this method at all.
     *
     * @return EloquentCollection<int, ProductionCostEntry>
     */
    private function productionCostTheDeleteVoided(Order $order): EloquentCollection
    {
        return ProductionCostEntry::query()
            ->where('order_id', $order->getKey())
            ->whereNull('reverses_entry_id')
            ->whereHas('reversal')
            ->get();
    }

    /**
     * Puts back the production cost the delete took away — **only what it actually took**.
     *
     * **A restore undoes a delete and nothing else.** An order deleted at «جاهزة للطباعة» has had
     * its stock drawn but has never been through the press: `ready_at` is null, no rate has ever
     * been applied to it, and the reversal therefore voided no rate-driven entry. Re-applying
     * anyway would cost a run that has not happened — and it would be costed *again* the day the
     * order finally reaches «جاهزة», where `ChangeOrderStatus` still finds `ready_at === null` and
     * applies the rates for what it believes is the first time. `labor_cost`, `overhead_cost`,
     * `order_items.cogs` and `orders.total_cogs` all come out exactly doubled, and nothing in the
     * ledger looks wrong: two rate-driven entries of the same amount is what a genuine reprint
     * would leave behind.
     *
     * **Where the delete did void rates, they go back on at today's** — the doc's §٣ («تُعيد
     * تطبيق قيود التصنيع بأسعار اليوم»), and the same bargain as the material: the reversal left
     * `order_items.labor_cost`/`overhead_cost` standing as a historical record while voiding the
     * entries under them, so recomputing `cogs` from those columns over a voided ledger would
     * bring the order back carrying wages the cost ledger says were cancelled.
     * {@see ApplyManufacturingRates} writes them again and
     * {@see RecalculateOrderItemManufacturingCost}, which it calls per line, restates the columns
     * from what is now actually standing.
     *
     * **Scrap is conserved, and at its own amount.** {@see RecordScrapLoss} writes a `ScrapLoss`
     * entry priced from the FIFO layers the spoiled bags actually came off, and the delete
     * reverses only a line's *fulfilment* draw — the scrap movement is never credited back, so
     * those bags stay off the shelf through the whole round trip. Voiding the entry and never
     * writing it again is the one outcome that cannot be right: the warehouse is short and nobody
     * is charged. It is re-written here rather than exempted inside
     * {@see ReverseOrderStockDeduction} for two reasons — that action is also the cancellation's,
     * where writing the whole of a written-off order's ledger down is the deliberate accounting
     * event, and an archived order has no business holding a live cost of any kind. And it is
     * re-written at the *recorded* amount, never re-priced: there is no rate to re-run and no new
     * movement to read layers off. The bags that spoiled cost what they cost.
     *
     * @param  EloquentCollection<int, ProductionCostEntry>  $voided
     */
    private function reapplyProductionCost(Order $order, EloquentCollection $voided, int $actorId): void
    {
        if ($voided->contains(fn (ProductionCostEntry $entry): bool => $entry->cost_type->isRateDriven())) {
            ($this->applyManufacturingRates)($order, $actorId);
        }

        foreach ($voided->where('cost_type', ManufacturingCostType::ScrapLoss) as $scrap) {
            $this->reinstateScrapLoss($scrap, $actorId);
        }
    }

    /**
     * Writes one voided scrap loss back onto the line, as a new entry rather than an un-voiding.
     *
     * Nothing in `production_cost_entries` is ever edited or deleted — the model's own rule — so
     * a loss that must stand again stands as a fresh row, leaving the original and its reversal
     * where they are. The foreman's own words come across verbatim: `notes` is his account of
     * what spoiled, and a round trip through the archive is no reason to put different words in
     * his mouth.
     */
    private function reinstateScrapLoss(ProductionCostEntry $scrap, int $actorId): void
    {
        $entry = new ProductionCostEntry;
        $entry->order_id = $scrap->order_id;
        $entry->order_item_id = $scrap->order_item_id;
        $entry->cost_type = ManufacturingCostType::ScrapLoss;
        // Rate-less, like every scrap entry — see ManufacturingCostType::isRateDriven().
        $entry->quantity = $scrap->quantity;
        $entry->rate = null;
        $entry->amount = $scrap->amount;
        $entry->recorded_by = $actorId;
        $entry->incurred_at = now();
        $entry->notes = $scrap->notes;
        $entry->save();
    }
}
