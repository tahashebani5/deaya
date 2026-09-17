<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Delivery\DeliveryService;
use App\Domain\Identity\Models\User;
use App\Domain\Order\DTOs\OrderPaymentData;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Enums\ShortageRevision;
use App\Domain\Order\Events\OrderEnteredShortage;
use App\Domain\Order\Events\OrderProfitFinalised;
use App\Domain\Order\Events\OrderStatusChanged;
use App\Domain\Order\Events\OrderStockDrawn;
use App\Domain\Order\Exceptions\FulfillmentRequiresAnActor;
use App\Domain\Order\Exceptions\OrderIsClosed;
use App\Domain\Order\Exceptions\OrderIsDeletedForStatusChange;
use App\Domain\Order\Exceptions\OrderLinesNeedAPrice;
use App\Domain\Order\Exceptions\OutsourcedOrderNeedsAVendor;
use App\Domain\Order\Exceptions\PaymentRequiresAnActor;
use App\Domain\Order\Exceptions\SettlementRequiresFullPayment;
use App\Domain\Order\Exceptions\ShortageMustBeResolved;
use App\Domain\Order\Exceptions\ShortageNeedsAQuantity;
use App\Domain\Order\Exceptions\TransitionNotAllowed;
use App\Domain\Order\Exceptions\TransitionRequiresReason;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderPayment;
use App\Domain\Order\Support\Money;
use App\Domain\Order\Support\TransitionFields;
use App\Domain\Vendor\VendorService;
use Illuminate\Support\Facades\DB;

/**
 * Moves an order, or refuses to.
 *
 * The only way a status changes. Everything the machine promises is enforced here — the map,
 * the reason a cancellation owes, the quantity a shortage owes, the stamp each milestone leaves
 * and the row in the timeline — so no caller can get half of it right.
 *
 * **The destination decides which way "out" means.** A clerk presses one button; whether that
 * lands on «استلام مكتب» or «جاري التوصيل» is read from the order's own fulfilment type rather
 * than taken from the request. A payload naming the wrong one of the two is corrected rather
 * than refused: the clerk did not choose it, so there is nothing to tell them off for.
 */
final class ChangeOrderStatus
{
    public function __construct(
        private readonly RecordStatusTransition $record,
        private readonly AddOrderDesign $addDesign,
        // The only writer of `shortage_quantity`, and the one that re-prices what it wrote.
        private readonly SetOrderShortages $setShortages,
        // Through the module's front door, never `ShippingCompany::query()` — the same seam
        // every other cross-context read here goes through.
        private readonly DeliveryService $delivery,
        // The only writer of `warehouse_quantity`, run immediately before the deduction that
        // reads it — see its own docblock.
        private readonly SetOrderStockQuantities $setStockQuantities,
        // The first real link to Inventory — see DeductOrderStock's own docblock for why it is
        // its own class rather than inlined here.
        private readonly DeductOrderStock $deductStock,
        // Costs labour, machine runtime and overhead the same moment stock leaves the warehouse
        // — see its own docblock for why it shares DeductOrderStock's guard.
        private readonly ApplyManufacturingRates $applyManufacturingRates,
        // The وسيط twin of the line above: turns each line's snapshotted unit cost into what the
        // line cost, at the same moment and for the same reason — see its own docblock.
        private readonly ApplyOutsourcingCosts $applyOutsourcingCosts,
        private readonly RecalculateOrderCogs $recalculateCogs,
        // Undoes both of the above when a cancellation follows a deduction — see its own
        // docblock.
        private readonly ReverseOrderStockDeduction $reverseStockDeduction,
        // Puts the shelf right at «جاهزة» when the press used a different amount than the
        // warehouse pulled — see its own docblock for why a correction is a restatement rather
        // than a delta movement.
        private readonly RestateOrderStockDeduction $restateStock,
        // The ledger's own front door, used unchanged — see {@see recordPaymentForOrder()}. Money taken
        // at the counter gets the same lock, the same ceiling and the same row as money taken on
        // the payments screen, because it *is* the same event.
        private readonly RecordOrderPayment $recordPayment,
        private readonly ReverseOrderPayment $reversePayment,
        // Turns «what did the customer actually take» into the remainder, the shrunken invoice
        // and the shelf movement or the write-off that follows — see its own docblock.
        private readonly RecordPartialDelivery $recordPartialDelivery,
        // Re-derives the order's totals once the accept dialog's prices land on the lines. The
        // only place an order's total is decided, so a quoted request reaches the same figure a
        // clerk-typed one would.
        private readonly RecalculateOrderTotals $recalculateTotals,
    ) {}

    /**
     * @param  array<string, mixed>  $fields  What the move asked for — see {@see TransitionFields}.
     *
     * @throws OrderIsClosed
     * @throws OrderIsDeletedForStatusChange
     * @throws TransitionNotAllowed
     * @throws TransitionRequiresReason
     * @throws SettlementRequiresFullPayment
     * @throws ShortageMustBeResolved
     * @throws FulfillmentRequiresAnActor
     * @throws PaymentRequiresAnActor
     */
    public function __invoke(
        Order $order,
        OrderStatus $target,
        ?string $reason = null,
        ?User $actor = null,
        array $fields = [],
    ): Order {
        $from = $order->status;

        if ($from->isFinal()) {
            throw OrderIsClosed::make($from);
        }

        $target = $this->resolve($order, $target);

        // **The order's own road, not the general one.** An order made entirely of goods that are
        // already made walks جديدة → جاهزة — see {@see OrderFlow} — and asking the map without
        // saying so would refuse the very move `Order::availableTransitionsFor()` had just told
        // the app it could make, leaving the short road drawn on screen and enforced nowhere.
        if (! $from->canMoveTo($target, $order->production_flow)) {
            throw TransitionNotAllowed::make($from, $target);
        }

        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        if ($target->requiresReason() && $reason === null) {
            throw TransitionRequiresReason::make($target);
        }

        // **Accepting a customer's request is where the vendor rule binds.**
        //
        // `CreateOrder` and `UpdateOrder` both skip that rule while an order is «بانتظار
        // المراجعة»: the customer app cannot name a vendor — the customer does not know we
        // outsource anything, and it is not their choice — so a request is allowed to be
        // incomplete in exactly this one way. An *order* is not. «جديدة» means verified and
        // ready to be worked on, and an outsourced order arriving there with nobody named is one
        // the shop cannot chase.
        //
        // So the refusal lands on the button that turns a request into an order, and it lands
        // before the transaction opens — nothing has been written yet, and the reviewer is told
        // what is missing rather than shown a half-accepted request.
        //
        // The order's road is already known by this point: `ResolveOrderFlow` runs at intake for
        // «بانتظار المراجعة» as well as «جديدة», precisely so the review screen can say «هذه
        // تحتاج وسيطاً» instead of the reviewer discovering it here.
        // **The field the move carries counts as an answer.** `TransitionFields` offers
        // `vendor_id` on exactly this move, so the accept dialog asks the question and sends it
        // back here — the reviewer answers in the same tap rather than being refused and sent to
        // the edit screen first. It is written a few lines below, inside the transaction; this
        // only has to know that an answer arrived.
        $acceptingARequest = $from === OrderStatus::Requested && $target === OrderStatus::New;
        $namedVendor = $acceptingARequest
            ? self::vendorIdIn($fields)
            : null;

        if ($acceptingARequest
            && $order->production_flow->needsAVendor()
            && $order->vendor_id === null
            && $namedVendor === null) {
            throw OutsourcedOrderNeedsAVendor::make();
        }

        // **Nothing leaves «بانتظار المراجعة» carrying a line nobody has priced.**
        //
        // This is the guard the whole nullable-price arrangement rests on. A request from the
        // app for something the catalogue prices «حسب الطلب» is written with `unit_price` null,
        // and while that is true the order's stored totals understate it — see
        // {@see RecalculateOrderTotals}, which says so out loud. That is only safe because an
        // unpriced line cannot reach a status anything bills from, and this is what makes it
        // true. Delete it and an accepted order can invoice less than the goods are worth, with
        // no column able to say it was wrong.
        //
        // The prices the accept dialog collected are applied a few lines below, inside the
        // transaction; this only has to know that an answer arrived for every line.
        $unpricedAfterFields = $acceptingARequest
            ? self::linesLeftUnpriced($order, $fields)
            : [];

        if ($unpricedAfterFields !== []) {
            throw OrderLinesNeedAPrice::make($unpricedAfterFields);
        }

        return DB::transaction(function () use ($order, $from, $target, $reason, $actor, $fields, $namedVendor, $acceptingARequest): Order {
            // **Before anything else, because an archived order must not move.** See §٤ of
            // Docs/orders/ORDER-DELETE-AND-ARCHIVE.md and {@see OrderIsDeletedForStatusChange}.
            $this->guardTheOrderIsNotDeleted($order);

            // **The vendor the accept dialog named, written before anything reads the order
            // again.** Inside the transaction, so a move that fails further down does not leave
            // a request carrying a vendor nobody agreed to. The name is snapshotted beside the
            // id for the reason the city and the branch are: renaming a workshop must not
            // rewrite who made an order last year — see OUTSOURCED-PRODUCTS.md §5.
            if ($namedVendor !== null) {
                $vendor = app(VendorService::class)->find($namedVendor);

                $order->forceFill([
                    'vendor_id' => $vendor->getKey(),
                    'vendor_name' => (string) $vendor->name,
                ])->save();
            }

            // **The prices the accept dialog named, written before anything totals the order.**
            //
            // `unit_price` and `line_total` are not fillable — a request that could post them
            // could name its own price — so they are force-filled here exactly as
            // {@see AddOrderItem} does, and the total is *derived* rather than multiplied out
            // again, so the one rule about which quantity an invoice is built on keeps its
            // single home.
            //
            // Inside the transaction: a move refused further down must not leave a request
            // wearing prices nobody agreed to.
            if ($acceptingARequest) {
                $this->applyQuotedPrices($order, $fields);
            }

            // **What the customer actually took, before the money that pays for it.** «تم
            // الاستلام» can carry a per-line count of what was handed over — see
            // {@see RecordPartialDelivery} — and recording it shrinks the invoice. The payment
            // below is bounded by what is still owed, so the two are in this order or an
            // accountant handing over three hundred of five hundred bags could pay against the
            // five hundred and leave the order overpaid by the difference.
            //
            // **Deliberately before the status is written**, unlike the stock work further down.
            // Nothing here reads the status; everything here is read by the guards and the
            // ledger entry between this line and that one.
            $restockedOnDelivery = $this->recordPartialDeliveryForOrder($order, $target, $fields, $actor);

            // **Money next, because the guard below reads what this writes.** «تم الاستلام» and
            // «تم التسوية» each carry a box for what was just handed over, and an accountant who
            // types the remainder into it is settling the order *with* that payment — so it has
            // to land before the settlement rule looks at the balance. Everything is one
            // transaction, so a move that is then refused takes its entry back with it.
            $recorded = $this->recordPaymentForOrder($order, $target, $fields, $actor);

            // **Walking back out of «عربون مدفوع» takes back what the move itself wrote.** The
            // claim is being withdrawn — the money did not arrive after all — so the entry that
            // move created is reversed, in this same transaction. Deliberately only that entry:
            // a deposit somebody recorded on the payments screen is not this move's to undo, and
            // whoever decides it was never received reverses it where it was made.
            $this->reverseDepositClaim($order, $from, $target, $reason, $actor);

            // **The last step on the line is the money, and it may not be skipped.** «تم التسوية»
            // is the statement that what the order was sent out to collect came back; an order
            // reaching it while its payment status still reads «غير مدفوعة» closes the order and
            // loses the debt in the same move. Read from the ledger's cached total rather than
            // taken on trust from the person pressing the button — see
            // {@see SettlementRequiresFullPayment}.
            if ($target === OrderStatus::Settled && $order->paymentStatus()->isOutstanding()) {
                throw SettlementRequiresFullPayment::make($order->remainingAmount());
            }

            // **Two statuses can be the one where stock leaves, and `stock_deducted_at` decides
            // which.** «جاهزة للطباعة» is the warehouse handing the goods to the press, and it is
            // the first stop for every printed order. An order of ready-made goods never passes
            // through it — see {@see OrderFlow} — so for that one «جاهزة» is still the first and
            // only deduction, exactly as before this status existed.
            //
            // Nothing returns to either — see `OrderStatus::allowedNext()` — and the column is
            // never cleared, so "at most once per order" holds without a second flag. The same
            // fact drives the form: {@see TransitionFields} offers the warehouse picker on
            // whichever of the two is still the deducting one, so what is asked for and what is
            // taken cannot disagree.
            //
            // **And one road takes nothing at all.** A وسيط order's goods are made by an outside
            // vendor and never sit on a shelf of ours, so «جاهزة» there means the vendor handed
            // them over — not that a warehouse gave anything up. Asked of the flow rather than
            // written out as a status test, so the day a fourth road arrives it answers for
            // itself; see {@see OrderFlow::deductsStock()}.
            $deductStock = ($target === OrderStatus::ReadyToPrint || $target === OrderStatus::Ready)
                && $order->production_flow->deductsStock()
                && $order->stock_deducted_at === null;

            // **The other half: «جاهزة» reached by an order whose stock already went.** The
            // warehouse weighed what it pulled; the press knows what the run actually used, and
            // the second figure is the true one. Only lines whose number moved are touched — see
            // {@see RestateOrderStockDeduction}.
            $restateStock = $target === OrderStatus::Ready && $order->stock_deducted_at !== null;

            // **Production is costed the first time an order is called ready, and the fact that
            // says so is now `ready_at`.** It used to be `stock_deducted_at`, because the two
            // happened in the same breath — but stock leaves at «جاهزة للطباعة» now, so every
            // printed order arrives here already deducted and that guard would cost nothing at
            // all. Read before the save below, which is what stamps the column.
            $costProduction = $target === OrderStatus::Ready && $order->ready_at === null;

            // The mirror image: a cancellation only has anything to undo if stock genuinely left
            // — `stock_deducted_at` is never cleared by a reversal, so this reads the same fact
            // `deductStock` above already relies on, not a second copy of it.
            $reverseStock = $target === OrderStatus::Cancelled && $order->stock_deducted_at !== null;

            $attributes = ['status' => $target];

            if ($column = $target->timestampColumn()) {
                $attributes[$column] = now();
            }

            if ($target === OrderStatus::Cancelled) {
                $attributes['cancellation_reason'] = $reason;
            }

            // **A refusal's reason is its own column, and it leaves for the customer's phone.**
            // `cancellation_reason` answers "why did we write this off" for the accountant;
            // this answers "why would you not take my order" for the person who placed it. One
            // column holding both would put the first sentence on the second screen.
            if ($target === OrderStatus::RequestRejected) {
                $attributes['rejection_reason'] = $reason;
            }

            // **Going back to the queue clears the refusal.** An order returned to «بانتظار
            // المراجعة» is waiting to be looked at again, and a stale sentence saying why it
            // was once refused would still be on it — and would still be on the customer's
            // screen — while it sat there awaiting a fresh answer.
            if ($target === OrderStatus::Requested) {
                $attributes['rejection_reason'] = null;
                $attributes['request_rejected_at'] = null;
            }

            // Who took it. The name is snapshotted beside the key for the same reason the
            // city's is: what an order says carried it is a fact about that day, and it must
            // survive the company being renamed or removed from the list.
            if ($target === OrderStatus::OutForDelivery && isset($fields['shipping_company_id'])) {
                $carrier = $this->delivery->findShippingCompany((int) $fields['shipping_company_id']);

                $attributes['shipping_company_id'] = $carrier->getKey();
                $attributes['shipping_company'] = $carrier->name;
                $attributes['courier_phone'] = $fields['courier_phone'] ?? null;
            }

            // **The arrangement, not a payment.** «انتظار العربون» records what the shop asked
            // for and how it expects it; nothing is written to the ledger, and no total moves.
            // Re-entering after a walk back overwrites both, which is right — the figure on the
            // order is whatever was last agreed.
            if ($target === OrderStatus::AwaitingDeposit) {
                // **An invoice of nothing parks here with a zero on it, and nobody was asked.**
                // A discount that swallowed the total leaves an order with no عربون to name, so
                // {@see TransitionFields} draws no box for it — and the figure is written here
                // rather than left null, because «العربون 0» tells the next person to open the
                // order that there was never money on this road, while an empty column tells
                // them only that nobody filled it in. The method goes with it: no payment is
                // coming, so there is no way for one to arrive.
                if (bccomp((string) $order->grand_total, '0', Money::SCALE) <= 0) {
                    $attributes['deposit_expected_amount'] = Money::normalize('0');
                    $attributes['deposit_expected_method'] = null;
                } else {
                    $asked = $fields[TransitionFields::DEPOSIT_AMOUNT] ?? null;

                    if ($asked !== null && $asked !== '') {
                        $attributes['deposit_expected_amount'] = Money::normalize($asked);
                    }

                    $method = $fields[TransitionFields::DEPOSIT_METHOD] ?? null;

                    if ($method !== null && $method !== '') {
                        $attributes['deposit_expected_method'] = PaymentMethod::from((string) $method);
                    }
                }
            }

            // **Who claimed it.** The name is stamped beside the stamp because the whole of the
            // four-eyes rule reads it: `ConfirmDepositReceipt` refuses this user, so an order
            // whose claim carries nobody's name — a console move, an import — bars nobody.
            // `deposit_paid_at` is written by the timestamp column above, like every milestone.
            if ($target === OrderStatus::DepositPaid) {
                $attributes['deposit_claimed_by'] = $actor?->getKey();

                // What this move put in the ledger, if it put anything there — null on the
                // ordinary move where the clerk records nothing, and the only entry a walk back
                // will reverse.
                $attributes['deposit_payment_id'] = $recorded?->getKey();
            }

            // **A withdrawn claim leaves nothing behind claiming.** The three columns the move
            // wrote are cleared together, so an order sent back to wait for its عربون is not
            // sitting in the accountant's «مُعلَن ولم يُؤكَّد» queue — it is waiting on money, and
            // that is what its status says.
            //
            // `is_deposit_received` is deliberately **not** cleared: it is one employee's
            // statement that they saw the money, and only an employee takes it back. An order
            // that carries both is a contradiction worth showing rather than tidying away — see
            // ORDER-DEPOSIT-PLAN.md §٣٫٥.
            if ($from === OrderStatus::DepositPaid && $target === OrderStatus::AwaitingDeposit) {
                $attributes['deposit_paid_at'] = null;
                $attributes['deposit_claimed_by'] = null;
                $attributes['deposit_payment_id'] = null;
            }

            // Only when it differs from the invoice — see {@see TransitionFields}. An empty
            // field settles the order at its own total and leaves the column null, so a value
            // here always means somebody counted something different.
            if ($target === OrderStatus::Settled) {
                $collected = $fields['collected_amount'] ?? null;

                $attributes['collected_amount'] = $collected === null || $collected === ''
                    ? null
                    : $collected;
            }

            // Stamped here so it lands in the same save as the status change — trusted the same
            // way `shipping_company_id` is trusted on `OutForDelivery`: TransitionFields already
            // required this field for exactly this case, so a caller that skipped that layer
            // (a console command, an importer) simply does not get a deduction, rather than the
            // domain re-deriving what the request already settled.
            if ($deductStock && isset($fields['warehouse_id'])) {
                $attributes['stock_deducted_at'] = now();
                $attributes['fulfillment_warehouse_id'] = (int) $fields['warehouse_id'];
            }

            // **The artwork is attached while the order stands in the status that accepts it.**
            // Only «قيد التصميم» does — see `designsAreEditable()` — and a move carrying artwork
            // is either arriving there or leaving it, so which side of the status write the
            // attachment falls on is decided by the order, not fixed in the code:
            //
            // - Leaving design for the press: the *old* status is the permitting one, so the
            //   versions go on before the move is written. This is the designer finishing.
            // - Arriving in design — «جديدة» forward, or the correction path back from «قيد
            //   الطباعة» — the *new* status is the permitting one, so the move is written first.
            //
            // Everything here is one transaction either way, so a design that turns out to
            // belong to somebody else takes the status change back with it: there is no order
            // left holding a version it should never have had, and none left in a status it was
            // only moved to in order to carry one.
            $artworkGoesFirst = $order->designsAreEditable();

            if ($artworkGoesFirst) {
                $this->attachDesigns($order, $fields);
            }

            // **Before the status moves, for the same reason the artwork sometimes is.** What
            // arrived of a shortage is a fact about the order as it still stands in «نواقص»,
            // where its lines are open; the status it is heading for closes them. See the
            // method's own docblock.
            $this->recordArrivedShortages($order, $from, $target, $fields, $actor);

            // And judged immediately after, so the order is refused on what it actually still
            // owes rather than on what it owed before the delivery was counted in.
            $this->guardShortageIsResolved($order, $from, $target);

            $order->forceFill($attributes)->save();

            if (! $artworkGoesFirst) {
                $this->attachDesigns($order, $fields);
            }

            $this->recordDeclaredShortages($order, $target, $fields, $actor);

            $this->guardShortage($order, $target);

            if ($deductStock && isset($fields['warehouse_id'])) {
                // **Before the deduction, never after.** What leaves the shelf is
                // `warehouse_quantity ?? quantity`, so a figure written afterwards would be a
                // note about a movement that had already taken the wrong number.
                ($this->setStockQuantities)($order->loadMissing('items'), $fields);

                $this->deductStockForOrder($order, (int) $fields['warehouse_id'], $actor);
            }

            if ($restateStock) {
                $this->restateStockForOrder($order, $fields, $actor);
            }

            // **The press has its material, and whoever it bought it from is owed for it now.**
            // Dispatched after both the deduction and the restatement, so every line's
            // `fulfillment_stock_movement_id` already names the draw that will stand — the
            // figure a purchase is booked against must not be one a correction is about to
            // replace. Announced, not acted on; see {@see OrderStockDrawn}.
            if ($deductStock || $restateStock || $restockedOnDelivery) {
                OrderStockDrawn::dispatch((int) $order->getKey());
            }

            // **Costed at «جاهزة» on both roads, and never at «جاهزة للطباعة».** Labour, machine
            // runtime and overhead are the press running, which has not happened when the
            // warehouse hands the order over — and by the time it has, the figure the restatement
            // above just settled is the corrected one, which is the right basis to cost against.
            if ($costProduction) {
                $this->costProductionForOrder($order, $actor);
            }

            if ($reverseStock) {
                $this->reverseStockForOrder($order, $actor);
            }

            ($this->record)($order, $from, $target, $reason, $actor);

            // **The moment this order's money stops moving.** From «تم الاستلام» the state
            // machine offers only «تم التسوية», and `UpdateOrder` refuses every edit on a closed
            // order — so `grand_total` and `total_cogs` are both frozen and the profit is final.
            //
            // Announced rather than acted on: Orders does not know that investors exist, and a
            // direct call would close a dependency loop the container cannot build. See
            // {@see OrderProfitFinalised}.
            if ($target === OrderStatus::Delivered || $target === OrderStatus::Settled) {
                OrderProfitFinalised::dispatch((int) $order->getKey());
            }

            // Read once for the two announcements below, both of which owe their listener the
            // person who moved the order so that person is not told about their own tap.
            $actorId = $actor?->getKey() === null ? null : (int) $actor->getKey();

            // **The order is stuck and a person has to do something about it.** Announced rather
            // than acted on, like the three above — but its listener is the one that is *queued
            // and deferred to after commit*, because it tells people rather than moving money.
            // A notification about a transaction that then rolled back cannot be taken back.
            if ($target === OrderStatus::Shortage) {
                OrderEnteredShortage::dispatch((int) $order->getKey(), $actorId);
            }

            // **Every move, including the ones nobody is told about.** The four announcements
            // above each name one moment; this one names the fact that a moment happened, and
            // leaves «is this worth telling anyone» to its listener — which is the only place
            // that list should live. See {@see OrderStatusChanged}.
            OrderStatusChanged::dispatch((int) $order->getKey(), $from, $target, $actorId);

            return $order->refresh();
        });
    }

    /**
     * The money that came with the move, written into the ledger as an ordinary payment.
     *
     * **Nothing is invented here.** The amount is what a person typed and the method is what
     * they picked; an empty box records nothing, which is the right answer for an order that was
     * paid weeks ago. That is the whole distinction the payments spec draws — a server that
     * derives an entry from a settlement is writing a collection nobody made, while a server
     * that stores what the person holding the cash typed is doing what the ledger is for.
     *
     * **Through {@see RecordOrderPayment} rather than a `create()` here**, so this path inherits
     * every guard the payments screen has: the row lock that makes two clerks collecting at once
     * safe, the refusal of anything over the remainder, and the receipt rule. A second way to
     * write a payment would be a second set of rules to keep in step.
     *
     * A zero is treated as an empty box rather than refused. Somebody who types it means "none",
     * and answering that with «المبلغ يجب أن يكون أكبر من صفر» is a form arguing with a person
     * who has already said what they meant.
     *
     * **Returns what it wrote, or null**, because one caller needs to know: a move into «عربون
     * مدفوع» stamps the entry it created onto the order, so a later walk back knows which row is
     * its own to reverse and which was recorded elsewhere by somebody else.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws PaymentRequiresAnActor
     */
    private function recordPaymentForOrder(
        Order $order,
        OrderStatus $target,
        array $fields,
        ?User $actor,
    ): ?OrderPayment {
        $amount = $fields[TransitionFields::PAYMENT_AMOUNT] ?? null;

        if ($amount === null || $amount === '' || bccomp(Money::normalize($amount), '0', Money::SCALE) <= 0) {
            return null;
        }

        if ($actor === null) {
            throw PaymentRequiresAnActor::make();
        }

        $payment = ($this->recordPayment)($order, OrderPaymentData::fromArray([
            'amount' => $amount,
            'method' => $fields[TransitionFields::PAYMENT_METHOD] ?? null,
            // The move's own note is the transition's, not the entry's: it explains why the
            // status changed, and copying it onto a payment row would put «المندوب خصم أجرة
            // التوصيل» beside a figure it does not describe.
            'notes' => "سُجِّلت مع نقل الطلبية إلى «{$target->label()}»",
            // Stored by the same action that stores one taken on the payments screen, into the
            // same five columns. Absent unless a file was actually attached — and a file
            // attached with no amount beside it reaches nothing, because the guard above has
            // already returned: a receipt with no entry to hang on would be an orphan.
            'receipt' => $fields[TransitionFields::PAYMENT_RECEIPT] ?? null,
        ]), $actor);

        // `RecordOrderPayment` recalculates against its own locked copy, so the instance this
        // action is holding still carries the old `paid_amount` — and the settlement guard three
        // lines down reads exactly that. Refreshing here rather than there keeps the reason
        // beside the write that caused it.
        $order->refresh();

        return $payment;
    }

    /**
     * Takes back the entry a «عربون مدفوع» move wrote, when that move is walked back.
     *
     * **Only the entry the move itself created.** `deposit_payment_id` is null unless this
     * feature put a row there, so a deposit typed into the payments screen — before the move or
     * days after it — is untouched: the status change did not write it, and a status change that
     * deleted somebody else's ledger entry would be the same lie in the other direction.
     *
     * **Already-reversed is not an error here.** An accountant may have reversed the entry from
     * the payments screen an hour before the clerk walked the status back, and refusing the move
     * for it would leave the order stranded in a status everyone agrees is wrong.
     *
     * `is_deposit_received` is not touched — see the walk-back block in the transaction.
     */
    private function reverseDepositClaim(
        Order $order,
        OrderStatus $from,
        OrderStatus $target,
        ?string $reason,
        ?User $actor,
    ): void {
        if ($from !== OrderStatus::DepositPaid || $target !== OrderStatus::AwaitingDeposit) {
            return;
        }

        $payment = $order->depositPayment()->first();

        if ($payment === null || $payment->isReversed()) {
            return;
        }

        ($this->reversePayment)(
            $order,
            $payment,
            $reason ?? 'أُعيدت الطلبية إلى «انتظار العربون»',
            $actor,
        );

        // Same reason as the refresh after recording one: the reversal recalculated against its
        // own locked copy, and everything below reads this instance's totals.
        $order->refresh();
    }

    /**
     * Records what the customer left behind, on the one move that can know.
     *
     * **Only «تم الاستلام».** That is the moment the goods and the customer are in the same
     * place; every other status is a statement about work, not about a handover.
     *
     * **An actor is required, and its absence is a refusal rather than a silent skip.** The loss
     * entry names who recorded it and the stock return names who moved it — the same reason
     * {@see deductStockForOrder()} refuses. A console command moving an order carries no fields,
     * so it never reaches this.
     *
     * `$order->refresh()` afterwards for the reason the payment below refreshes: the action
     * rewrote `items_total` and `grand_total` through its own query, and the instance in hand
     * would otherwise still be holding the figures the customer did not agree to.
     *
     * **The return value is a debt.** A سادة line that goes back on the shelf is credited to the
     * cost layers it came off — which, for material bought off an investor's deal, are *his* — so
     * whoever was paid سعر السادة for those bags at «جاهزة» must be un-paid for the ones that
     * came back, or he holds the money and the goods at once. `OrderStockDrawn` above is what
     * tells Investment to recompute; it is announced by this method's caller rather than by the
     * action, exactly as the deduction's and the restatement's are, so there is one place that
     * decides when that recomputation happens.
     *
     * @param  array<string, mixed>  $fields
     * @return bool whether anything went back on a shelf
     *
     * @throws FulfillmentRequiresAnActor
     */
    private function recordPartialDeliveryForOrder(Order $order, OrderStatus $target, array $fields, ?User $actor): bool
    {
        if ($target !== OrderStatus::Delivered) {
            return false;
        }

        // Cheap and total: the action itself skips every line whose box came back holding the
        // figure it was given, but building the collection to discover that on an ordinary
        // delivery is work nobody asked for.
        $answered = array_filter(
            $fields,
            fn (string $key): bool => str_starts_with($key, 'delivered_'),
            ARRAY_FILTER_USE_KEY,
        );

        if ($answered === []) {
            return false;
        }

        if ($actor === null) {
            throw FulfillmentRequiresAnActor::make();
        }

        $restocked = ($this->recordPartialDelivery)($order->loadMissing('items'), $fields, (int) $actor->getKey());

        $order->refresh();

        return $restocked;
    }

    /**
     * Either dispatch status means "it is leaving"; the city says which one that is.
     */
    private function resolve(Order $order, OrderStatus $target): OrderStatus
    {
        return $target->isDispatch()
            ? OrderStatus::dispatchFor($order->fulfilment_type)
            : $target;
    }

    /**
     * Versions of the artwork that came with the move.
     *
     * Each goes through {@see AddOrderDesign}, so a design belonging to another customer is
     * refused by the one place that knows the rule, and the version numbers are allocated the
     * same way they are when a design is added on its own.
     *
     * @param  array<string, mixed>  $fields
     */
    private function attachDesigns(Order $order, array $fields): void
    {
        foreach ((array) ($fields['design_ids'] ?? []) as $designId) {
            ($this->addDesign)($order, (int) $designId);
        }
    }

    /**
     * What is missing, written against the line it is missing from — on the way in and on the
     * way out.
     *
     * **Two questions, one write.** Arriving asks «كم الناقص» per line, because asked of a whole
     * order it has no answer — it is a question about a size. Leaving asks «كم وصل منه», which
     * is the same fact from the other end: the stock turned up, and the invoice the shortage cut
     * has to come back with it. Both land in {@see SetOrderShortages}, so the money is re-derived
     * once however the number moved.
     *
     * Cancelling is deliberately neither. An order written off while short keeps the record of
     * what was short when it was written off; asking a clerk what arrived, of a job nobody is
     * going to do, would be a form standing between them and the decision.
     *
     * @param  array<string, mixed>  $fields
     */
    private function recordDeclaredShortages(
        Order $order,
        OrderStatus $target,
        array $fields,
        ?User $actor,
    ): void {
        if ($target === OrderStatus::Shortage) {
            ($this->setShortages)($order, $this->declared($order, $fields), $actor, ShortageRevision::Declared);
        }
    }

    /**
     * What arrived of the shortage, written **before** the order leaves «نواقص».
     *
     * **The side of the status write this falls on is decided by the same rule the artwork
     * follows.** {@see SetOrderShortages} refuses an order whose lines are locked, and «نواقص» is
     * one of the four statuses where they are not — but «جاهزة للطباعة», the status this move
     * usually lands on, is not: the goods have been weighed and the stock has already left the
     * warehouse by then, so the lines close there on purpose. Recorded after the save, this would
     * refuse every delivery that resolved a shortage.
     *
     * So it is recorded while the order still stands in the status that permits it — exactly as
     * `$artworkGoesFirst` decides which side of the write the designs go on.
     *
     * @param  array<string, mixed>  $fields
     */
    private function recordArrivedShortages(
        Order $order,
        OrderStatus $from,
        OrderStatus $target,
        array $fields,
        ?User $actor,
    ): void {
        if ($from === OrderStatus::Shortage && ! $target->isFinal()) {
            ($this->setShortages)($order, $this->remaining($order, $fields), $actor, ShortageRevision::Received);
        }
    }

    /**
     * The shortages as the clerk typed them: absolute, one per line.
     *
     * @param  array<string, mixed>  $fields
     * @return array<int, mixed>
     */
    private function declared(Order $order, array $fields): array
    {
        $shortages = [];

        foreach ($order->items as $item) {
            $shortages[(int) $item->getKey()] = $fields["shortage_{$item->getKey()}"] ?? null;
        }

        return $shortages;
    }

    /**
     * What is *still* missing once the delivery has been counted in.
     *
     * The field asks what arrived rather than what is left, because that is the number the
     * person holding the delivery note has. An untouched field means the whole shortage arrived
     * — it is pre-filled with exactly that, so leaving it alone is an answer rather than a
     * silence — and the subtraction is done here so nobody does it in their head.
     *
     * @param  array<string, mixed>  $fields
     * @return array<int, mixed>
     */
    private function remaining(Order $order, array $fields): array
    {
        $shortages = [];

        foreach ($order->items as $item) {
            $short = (string) ($item->shortage_quantity ?? '0');
            $received = $fields["received_{$item->getKey()}"] ?? $short;
            $received = $received === null || $received === '' ? $short : (string) $received;

            $left = bcsub($short, $received, 3);
            $shortages[(int) $item->getKey()] = bccomp($left, '0', 3) > 0 ? $left : null;
        }

        return $shortages;
    }

    /**
     * A «نواقص» that does not say what is missing is a status nobody can act on.
     *
     * The fields are each optional — most shortages are one size out of several, and marking
     * them all required would have staff typing zeros to get past the form — so the rule that
     * matters is this one: at least one line short by something.
     *
     * @throws ShortageNeedsAQuantity
     */
    private function guardShortage(Order $order, OrderStatus $target): void
    {
        if ($target !== OrderStatus::Shortage) {
            return;
        }

        $recorded = $order->items()
            ->whereNotNull('shortage_quantity')
            ->where('shortage_quantity', '>', 0)
            ->exists();

        if (! $recorded) {
            throw ShortageNeedsAQuantity::make();
        }
    }

    /**
     * Refuses to move an order that is sitting in the archive.
     *
     * **Locked and re-read rather than answered from `$order`, because the whole point is the
     * race.** A delete and a forward move can both bind the same live order, and both then think
     * it is live: the delete archives it and credits its goods back while this action walks on
     * into «جاهزة للطباعة», draws 300 bags out of the warehouse for an order that no longer
     * appears in any list, and dispatches `OrderStockDrawn` so an investor is paid for them.
     * Nothing in the database catches it — a *deduction* has no reversal to collide with, unlike
     * the two double-delete cases beside it (§٤). Asking `$order->trashed()` from the model bound
     * before the transaction opened would read the answer from before the race and re-lose it.
     *
     * The lock is the same one {@see DeleteOrder} takes as its own first statement, so whichever
     * of the two arrives second waits for the first to commit and then reads the truth.
     * `withTrashed()` for the reason that action gives: the scoped query cannot see the very row
     * this is asking about.
     *
     * @throws OrderIsDeletedForStatusChange
     */
    private function guardTheOrderIsNotDeleted(Order $order): void
    {
        $locked = Order::withTrashed()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->trashed()) {
            throw OrderIsDeletedForStatusChange::make((string) $locked->code);
        }
    }

    /**
     * Hands off to {@see DeductOrderStock} with the order's lines loaded — strict-mode lazy
     * loading is on outside production, so `items` has to be fetched explicitly here rather than
     * left for `DeductOrderStock` to touch cold, the same reason {@see recordShortages()} never
     * runs for a target that does not need the relation.
     *
     * @throws FulfillmentRequiresAnActor
     */
    private function deductStockForOrder(Order $order, int $warehouseId, ?User $actor): void
    {
        if ($actor === null) {
            throw FulfillmentRequiresAnActor::make();
        }

        ($this->deductStock)($order->loadMissing('items.product'), $warehouseId, (int) $actor->getKey());
    }

    /**
     * Standard-costs the labour, machine runtime and overhead behind whatever
     * {@see deductStockForOrder} just took off the shelf, then rolls both into the order's own
     * `total_cogs`.
     *
     * `$actor` is never null here: this only ever runs immediately after `deductStockForOrder`,
     * which already refused a null one.
     */
    private function costProductionForOrder(Order $order, ?User $actor): void
    {
        // It used to run only immediately after `deductStockForOrder()`, which had already
        // refused a null actor, so the parameter could be non-nullable. Costing now happens on
        // its own at «جاهزة» — after a *restatement* as well as after a first deduction — so the
        // refusal has to be stated here rather than inherited from a call site next door.
        if ($actor === null) {
            throw FulfillmentRequiresAnActor::make();
        }

        ($this->applyManufacturingRates)($order->loadMissing('items'), (int) $actor->getKey());

        // **Both, unconditionally, and neither asks about the road.** A printed order carries no
        // `unit_cost` on any line and a وسيط order has no rates to apply, so each action is a
        // no-op on the other's orders — and a mixed order, which the flow rules make unlikely
        // rather than impossible, is costed correctly line by line instead of one way or the
        // other. Asking `production_flow` here would be a third copy of a decision the lines
        // already carry.
        ($this->applyOutsourcingCosts)($order);

        ($this->recalculateCogs)($order);
    }

    /**
     * Puts the shelf right once the press knows what the run actually used.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws FulfillmentRequiresAnActor
     */
    private function restateStockForOrder(Order $order, array $fields, ?User $actor): void
    {
        if ($actor === null) {
            throw FulfillmentRequiresAnActor::make();
        }

        ($this->restateStock)($order->loadMissing('items'), $fields, (int) $actor->getKey());
    }

    /**
     * An order still short of something is not ready for the press.
     *
     * **Only on the way into «جاهزة للطباعة», and only from «نواقص».** That move is a promise to
     * another department that the goods are all here; every other move an order short of stock
     * can make — being written off, having the shortage corrected — is left alone, because none
     * of them tells anybody the order is complete.
     *
     * Reads the lines fresh: `recordShortages()` has just written to them on this same move, and
     * the whole point is to judge the order as it stands *after* that.
     *
     * @throws ShortageMustBeResolved
     */
    private function guardShortageIsResolved(Order $order, OrderStatus $from, OrderStatus $target): void
    {
        if ($from !== OrderStatus::Shortage || $target !== OrderStatus::ReadyToPrint) {
            return;
        }

        $stillShort = $order->load('items')->unresolvedShortages();

        if ($stillShort !== []) {
            throw ShortageMustBeResolved::make($stillShort);
        }
    }

    /**
     * Hands off to {@see ReverseOrderStockDeduction} with the order's lines loaded — the same
     * eager-loading reasoning `deductStockForOrder()` already carries.
     *
     * @throws FulfillmentRequiresAnActor
     */
    private function reverseStockForOrder(Order $order, ?User $actor): void
    {
        if ($actor === null) {
            throw FulfillmentRequiresAnActor::make();
        }

        ($this->reverseStockDeduction)($order->loadMissing('items'), (int) $actor->getKey());
    }

    /**
     * The vendor id the move carried, if it carried one.
     *
     * A helper rather than an inline cast because the payload is untyped: `fields` arrives from
     * a request, and «سلسلة فارغة» is a thing an app sends when a picker was opened and closed.
     * Treating that as «nobody» is what keeps the guard above honest.
     *
     * @param  array<string, mixed>  $fields
     */
    /**
     * The lines that would still have no price after the fields on this move are applied.
     *
     * @param  array<string, mixed>  $fields
     * @return list<string> what to name in the refusal, one per line
     */
    private static function linesLeftUnpriced(Order $order, array $fields): array
    {
        $missing = [];

        foreach ($order->items as $item) {
            if ($item->isPriced()) {
                continue;
            }

            $quoted = $fields[TransitionFields::unitPriceKey($item)] ?? null;

            if ($quoted === null || $quoted === '') {
                $missing[] = trim("{$item->product_name} {$item->variant_label}");
            }
        }

        return $missing;
    }

    /**
     * Writes the quoted price onto every line that was waiting for one.
     *
     * @param  array<string, mixed>  $fields
     */
    private function applyQuotedPrices(Order $order, array $fields): void
    {
        foreach ($order->items as $item) {
            if ($item->isPriced()) {
                continue;
            }

            $quoted = $fields[TransitionFields::unitPriceKey($item)] ?? null;

            if ($quoted === null || $quoted === '') {
                continue;
            }

            $item->forceFill(['unit_price' => (string) $quoted]);
            $item->forceFill(['line_total' => $item->deriveLineTotal()])->save();
        }

        // The relation is stale now — the items in memory were priced through the loop above
        // and the totals are about to be read from the database.
        $order->load('items');

        ($this->recalculateTotals)($order);
    }

    private static function vendorIdIn(array $fields): ?int
    {
        $value = $fields[TransitionFields::VENDOR_ID] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
