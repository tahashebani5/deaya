<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Carrier\CarrierService;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Events\OrderProfitUnwound;
use App\Domain\Order\Exceptions\FulfillmentRequiresAnActor;
use App\Domain\Order\Exceptions\FulfillmentWarehouseIsDeleted;
use App\Domain\Order\Exceptions\OrderHasAnOpenParcel;
use App\Domain\Order\Exceptions\OrderIsAlreadyDeleted;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Support\StockEffectPreview;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Archives an order that should never have been written down, and puts its goods back.
 *
 * **Not «إلغاء تام», and the difference is not a matter of taste.** A cancellation says the order
 * happened and then ended, with a reason; a delete says the row was a mistake — a duplicate
 * entry, a wrong number, somebody trying the app. The two therefore come back differently:
 * {@see ReinstateCancelledOrder} deliberately does *not* re-deduct, because the goods really did
 * come back off a real cancellation, while {@see RestoreOrder} must, because an order that never
 * should have existed has to return exactly as it was — deducted. See §١ of
 * Docs/orders/ORDER-DELETE-AND-ARCHIVE.md.
 *
 * **The row is locked as the first statement inside the transaction, `withTrashed()`.** The lock
 * itself is the idiom the five money actions already use — {@see RecordOrderPayment} at the top
 * of its own transaction — and it is here for a sharper reason than theirs: a delete racing a
 * forward status move otherwise archives an order while `ChangeOrderStatus` is drawing stock for
 * it and paying an investor, and nothing in the database catches that because a deduction has no
 * reversal to collide with (§٤). What none of those five need, and this one does, is
 * **`withTrashed()`**: they lock an order that is by definition live, whereas the row this action
 * has most to say about is one a colleague deleted a moment ago — and the ordinary
 * `Order::query()` is soft-delete scoped, so it would answer `firstOrFail()` with a 404 exactly
 * when the honest answer is «محذوفة أصلاً».
 *
 * **Everything is re-read off the locked row** rather than off the `Order` that was passed in:
 * the bound model was read before the lock was taken, so its `paid_amount`, its `deleted_at` and
 * its lines are all what was true when the request started.
 *
 * **The money is reversed, not refused — §٢٫١, decided 2026-09-10.** This action used to throw
 * `OrderHasUnreversedMoney` at an order carrying so much as a write-off, on the grounds that a
 * reversal hidden inside a delete button is an accounting entry nobody chose to make. The user
 * chose the opposite, and the problem the refusal was aimed at is unchanged either way:
 * `ProfitAndLossSummaryQuery` reads revenue and cost through `Order::query()`, which the
 * soft-delete scope trims, while its cash and write-off halves read `order_payments` with no
 * join to `orders` at all — so a paid order deleted untreated makes the top of one report
 * describe a different set of orders from the bottom. Reversing closes that gap without sending
 * the user away to do it by hand first.
 *
 * **And it is still an append-only ledger**: no balance column is edited and no row is removed.
 * {@see ReverseOrderPayment} writes the second row that points at the first, exactly as a person
 * would from the payments screen, and {@see RecalculateOrderPayments} restates the derived
 * columns behind it. Writing those rows here by hand was the alternative and it loses on the one
 * thing that matters — the `isCredit()` refusal, the `isReversed()` check under the lock and the
 * recalculate would all have been re-implemented, and a ledger with two writers is a ledger with
 * two behaviours the day one of them is fixed.
 *
 * **The restore does not un-reverse.** §٢٫١ is explicit that reversing a reversal is a third
 * financial event nobody asked for; the payment is re-entered by hand if the money really was
 * taken. That is the sentence {@see StockEffectPreview} puts in front
 * of the button, and it is the reason this action may not assume {@see RestoreOrder} will undo it.
 *
 * **One refusal left, and it is not about money on the order.** An open نورس parcel would never
 * be closed again, silently ({@see OrderHasAnOpenParcel}) — see §٢٫٢. Everything else may be
 * deleted from any status, «إلغاء تام» included.
 */
final class DeleteOrder
{
    public function __construct(
        private readonly ReverseOrderStockDeduction $reverseStock,
        private readonly ReverseOrderPayment $reversePayment,
        // **The one place Order asks Carrier a question**, against the direction dependencies
        // run in RULES §3 — Carrier depends on Order and CarrierService's own docblock says
        // «`Order` never calls it at all». A domain event, the usual answer, cannot serve here:
        // this is a *guard*, and a guard needs its answer before it decides, not afterwards.
        // Asked through the Service rather than of `NawrisParcel`, so the seam stays one call.
        private readonly CarrierService $carrier,
    ) {}

    /**
     * @throws OrderIsAlreadyDeleted
     * @throws OrderHasAnOpenParcel
     * @throws FulfillmentWarehouseIsDeleted
     * @throws FulfillmentRequiresAnActor
     */
    public function __invoke(Order $order, ?User $actor = null): Order
    {
        return DB::transaction(function () use ($order, $actor): Order {
            $locked = Order::withTrashed()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->trashed()) {
                throw OrderIsAlreadyDeleted::make((string) $locked->code);
            }

            $parcel = $this->carrier->openParcelFor($locked);

            if ($parcel !== null) {
                throw OrderHasAnOpenParcel::make((string) $locked->code, (string) $parcel->code);
            }

            // **Before the delete, and that ordering is load-bearing rather than tidy.**
            // `ReverseOrderPayment` locks the order through the ordinary `Order::query()`, which
            // is soft-delete scoped — called after `delete()` below it would answer
            // `firstOrFail()` with a 404 on the row it is standing on. Money first also matches
            // the confirmation the user just read, where §٧٫١ puts it first as the heavier
            // consequence.
            $this->reverseMoney($locked, $actor);

            $drawn = $locked->linesWithStockStillDrawn();

            if ($drawn->isNotEmpty()) {
                $this->returnStock($locked, $drawn, $actor);

                // **Written before the delete, and this is the fact the restore is built on.**
                // Recomputing it later cannot work: «هل لهذه الطلبية عكسٌ حيّ؟» is equally true
                // of an order whose *cancellation* returned the goods, and re-deducting for that
                // one would take bags off a shelf this delete never touched.
                $locked->forceFill(['delete_returned_stock_at' => now()])->save();
            }

            $locked->delete();

            // **Announced rather than called, and RULES §3 is why** — see
            // {@see OrderProfitUnwound}. Inside the transaction on purpose, like the posting it
            // undoes: either the order leaves the books and the investors' earnings leave with
            // it, or neither happens.
            OrderProfitUnwound::dispatch((int) $locked->getKey());

            return $locked->refresh();
        });
    }

    /**
     * Writes a reversal against every live credit entry the order carries.
     *
     * **Nested inside this transaction, and its own lock is re-entrant.** `ReverseOrderPayment`
     * opens a `DB::transaction` of its own — a savepoint once there is an outer one — and takes
     * `lockForUpdate()` on a row this action already holds, which PostgreSQL grants to the same
     * transaction without waiting. So the whole delete stays one atomic unit: a shortfall on the
     * shelf below rolls the reversals back with it, and nothing can observe an order whose money
     * has been undone but whose goods are still out.
     *
     * **Neither of that action's two refusals can fire here**, and both are worth naming because
     * the filter in front is what keeps them silent: {@see Order::liveCreditEntries()} yields
     * only entries whose type `isCredit()` — a refund is left alone, being money that genuinely
     * left the drawer — and only those with no live reversal standing, so an entry a person
     * already reversed by hand is not reversed a second time into the unique index.
     *
     * The reason is the row's whole user interface a month later, so it names the delete and the
     * order rather than saying «تصحيح».
     */
    private function reverseMoney(Order $order, ?User $actor): void
    {
        $reason = "عكسٌ تلقائي عند حذف الطلبية «{$order->code}» — أُرشفت الطلبية وخرجت من الحسابات";

        foreach ($order->liveCreditEntries() as $entry) {
            ($this->reversePayment)($order, $entry, $reason, $actor);
        }
    }

    /**
     * Hands the goods back for the lines that still hold them.
     *
     * **{@see ReverseOrderStockDeduction} does the work, filtered rather than modified.** Its
     * own per-line test is `fulfillment_stock_movement_id === null`, which is right for the
     * cancellation it was written for — a line that never drew has nothing to undo — and wrong
     * here in one case: an order cancelled *before* it was deleted has a movement id on every
     * line and a live reversal against each, so that action would credit the same 300 bags a
     * second time and break `stock_movements_reverses_movement_id_unique` on the way out.
     *
     * Weakening its guard was the alternative and it loses badly: the cancellation path would
     * then be doing a ledger lookup it does not need, to answer a question it cannot ask wrongly.
     * Extracting the shared body into a third class was the other, and it buys nothing — the body
     * is «reverse this line's draw, void its cost entries», and there is only one of it.
     *
     * So the ledger check goes **in front**: `items` is set to exactly the lines whose draw still
     * stands, and the action's own guard becomes a belt over braces. Setting the relation rather
     * than passing a list is what keeps that action untouched — it reads `$order->items`, and
     * this is the supported way to tell an Eloquent model which ones those are.
     *
     * @param  EloquentCollection<int, OrderItem>  $drawn
     *
     * @throws FulfillmentWarehouseIsDeleted
     * @throws FulfillmentRequiresAnActor
     */
    private function returnStock(Order $order, EloquentCollection $drawn, ?User $actor): void
    {
        // Before a single movement, because the failure on the other side of it is worse than a
        // crash: `ApplyStockChange::growBalance()` would open a live balance row inside a retired
        // warehouse — real stock that no screen lists and no order can draw on. See §٣.
        if (! $order->fulfillmentWarehouse()->exists()) {
            throw FulfillmentWarehouseIsDeleted::make((string) $order->code);
        }

        // `StockMovement::employee_id` is not nullable, and a credit-back is a movement like any
        // other. The same refusal `ChangeOrderStatus` makes before it deducts, for the same
        // reason — see {@see FulfillmentRequiresAnActor}.
        if ($actor === null) {
            throw FulfillmentRequiresAnActor::make();
        }

        $order->setRelation('items', $drawn);

        ($this->reverseStock)($order, (int) $actor->getKey());
    }
}
