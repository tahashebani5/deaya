<?php

declare(strict_types=1);

namespace App\Domain\Order\Support;

use App\Application\Api\V1\Resources\OrderResource;
use App\Domain\Delivery\DeliveryService;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\InventoryService;
use App\Domain\Order\Actions\DeductOrderStock;
use App\Domain\Order\Actions\RecordPartialDelivery;
use App\Domain\Order\DTOs\TransitionField;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Enums\UndeliveredDisposition;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Support\DecimalText;

/**
 * What a particular order owes for a particular move.
 *
 * **One list, read twice.** The resource turns it into the form the app draws, and the request
 * turns it into the rules it accepts — so what is asked for and what is allowed cannot drift
 * apart, and adding a field to a move is a single change here.
 *
 * **It answers for the order, not for the status.** A run priced by the kilo cannot be shelved
 * without a weight while one sold by the piece can, «نواقص» asks a question per line in that
 * line's own unit, and an office pickup is never asked who is carrying the parcel. A table of
 * fields per status could express none of it.
 */
final class TransitionFields
{
    /** What the money box is called in the payload, and the key its method hangs off. */
    public const PAYMENT_AMOUNT = 'payment_amount';

    public const PAYMENT_METHOD = 'payment_method';

    public const PAYMENT_RECEIPT = 'payment_receipt';

    /**
     * What «انتظار العربون» asks for: the figure, and the way it is expected to arrive.
     *
     * **Keyed apart from the three above because they describe a different kind of thing.**
     * Those record money that has just changed hands and go to `RecordOrderPayment`; these
     * record money the shop is *waiting for*, and go nowhere near the ledger. One shared key
     * would put an expectation into the payment log, which is the one thing this design refuses
     * — see Docs/orders/ORDER-DEPOSIT-PLAN.md §٣٫٢.
     */
    public const DEPOSIT_AMOUNT = 'deposit_amount';

    public const DEPOSIT_METHOD = 'deposit_method';

    /**
     * Who is making an outsourced order, asked at the moment a customer's request is accepted.
     *
     * **The one field this list offers on a move *into* «جديدة»**, and it exists because of an
     * asymmetry the customer app creates: `CreateOrder` and `UpdateOrder` both skip the
     * outsourcing rule while an order is «بانتظار المراجعة», since a customer cannot name a
     * vendor and it is not their choice. The rule binds when the request becomes an order — and
     * without this field, satisfying it would mean a second call to `PATCH /orders/{order}`
     * before the accept button could be pressed at all.
     */
    public const VENDOR_ID = 'vendor_id';

    /**
     * [$actor] is who is making the move, and it decides one thing only: whether the money box
     * is offered. A driver may hand a parcel over without being trusted with the till, so the
     * field is withheld rather than the move. Null — a console command, an importer — is treated
     * as nobody, and gets no box.
     *
     * @return list<TransitionField>
     */
    public static function for(Order $order, OrderStatus $target, ?User $actor = null): array
    {
        // **Resolved exactly as the move itself resolves it.** A clerk says "it is going out"
        // and the destination decides whether that means «جاري التوصيل» or «استلام مكتب» — see
        // {@see ChangeOrderStatus::resolve()}. Describing the *requested* status instead would
        // ask an office-pickup order for a shipping company and then refuse the answer, because
        // the move it actually performs never asked.
        if ($target->isDispatch()) {
            $target = OrderStatus::dispatchFor($order->fulfilment_type);
        }

        $fields = [];

        // **A move carries artwork when the order stands in a status that accepts it, on one
        // side of the move or the other.** Two statuses do — «جديدة» and «قيد التصميم», see
        // {@see Order::designsAreEditable()} — and {@see ChangeOrderStatus} attaches while the
        // order is standing in whichever end allows it.
        //
        // Which gives three real moves. Into design, where the queue fills, usually with
        // nothing. Out of design to the press, where it empties with the finished file. And
        // «جديدة» straight to «قيد الطباعة» — the short path, and the commonest one: the customer
        // brought an agreed file, so the order carries it and the press starts, with no detour
        // through a status naming work nobody did. That last case was refused until «جديدة»
        // started accepting versions, and refusing it was the reason staff walked orders into
        // the designer's queue and straight back out.
        //
        // Anywhere else the field could not be honoured, and a field certain to be refused is
        // worse than no field.
        //
        // Every order, whatever its `design_source`. That column answers *whose work the artwork
        // was* — the only one of the two questions that may move money — and not whether there
        // is a file. A reprint that goes back into design because the customer wants the logo
        // moved has artwork to look at like any other.
        $artworkTravels = $target === OrderStatus::Designing
            || ($target === OrderStatus::Printing && $order->designsAreEditable());

        if ($artworkTravels) {
            $fields[] = TransitionField::customerDesigns(
                key: 'design_ids',
                label: 'التصاميم',
                // **Never required, and that is the point of the status.** «قيد التصميم» is the
                // designer's queue: an order is put there *because* the artwork does not exist
                // yet, and it waits there until it does. Demanding a file on the way in made the
                // status unreachable in exactly the case it was built for — and demanding one on
                // the way out would strand every order whose artwork was settled off-screen.
                required: false,
                hint: 'تُرفع إلى مكتبة العميل ثم تُربط بالطلبية',
            );
        }

        // **Accepting a customer's request for something a vendor makes.**
        //
        // Offered only on this one move, and only when the order actually needs it: the request
        // came from the app with no vendor — the customer neither knows nor chooses who makes
        // their bags — and `ChangeOrderStatus` refuses «بانتظار المراجعة» → «جديدة» until one is
        // named. Asking here means the reviewer answers it in the same tap that accepts, rather
        // than being refused and sent to the edit screen first.
        //
        // The road is already known: `ResolveOrderFlow` runs at intake for a request as well as
        // for «جديدة», precisely so this question can be asked before anybody is refused.
        if ($target === OrderStatus::New
            && $order->status === OrderStatus::Requested
            && $order->production_flow->needsAVendor()
            && $order->vendor_id === null) {
            $fields[] = TransitionField::vendor(
                key: self::VENDOR_ID,
                label: 'الوسيط',
                // Required, because this is the whole reason the field is here: the move is
                // refused without it, and offering it optionally would put the refusal back on
                // the other side of a button somebody already pressed.
                required: true,
                hint: 'من يصنع هذه الطلبية. يُسجَّل عليها ويبقى اسمه فيها',
            );
        }

        // **What the shop is charging for something the catalogue does not price.**
        //
        // Offered on the same move as the vendor above, and for a twin of the same reason: the
        // customer could not answer it. A product priced «حسب الطلب» is never sent a price by
        // the client API, so a request for one arrives with the line unpriced — see
        // {@see \App\Domain\Order\Actions\AddOrderItem}. Asking here means the reviewer quotes
        // it in the same tap that accepts, and `ChangeOrderStatus` refuses the move until every
        // line has a number.
        //
        // One box per unpriced line rather than one for the order: each line is a different
        // product at a different size, and a single figure could not say which.
        if ($target === OrderStatus::New && $order->status === OrderStatus::Requested) {
            foreach ($order->items as $item) {
                if ($item->isPriced()) {
                    continue;
                }

                $fields[] = TransitionField::number(
                    key: self::unitPriceKey($item),
                    label: "سعر الوحدة — {$item->product_name} ({$item->variant_label})",
                    // Required, like the vendor and for the same reason: the move is refused
                    // without it, and an optional box would put the refusal on the far side of
                    // a button somebody already pressed.
                    required: true,
                    // **No `max`, and `min` is left to validation.** What the shop charges for a
                    // made-to-order job is a commercial decision, and a ceiling invented here
                    // would be a number nobody agreed refusing a sale somebody did.
                    hint: 'الكمية '.DecimalText::trim((string) $item->quantity).' — يُحتسب المجموع بعد الاعتماد',
                );
            }
        }

        // Who is carrying it, and the man holding it.
        //
        // **Only on «جاري التوصيل».** The dispatch pair resolves from the order's own address
        // before it reaches here, so an office pickup never sees these: nobody carries a parcel
        // the customer is coming to collect.
        if ($target === OrderStatus::OutForDelivery) {
            // **The usual carrier, filled in rather than asked for.** Through the module's front
            // door, never `ShippingCompany::query()` — the same seam {@see ChangeOrderStatus}
            // reads the chosen company through. Null when nobody named one, and the box opens
            // empty exactly as it always did.
            $preferred = app(DeliveryService::class)->defaultShippingCompany();

            $fields[] = TransitionField::shippingCompany(
                key: 'shipping_company_id',
                label: 'شركة التوصيل',
                // Required, because a parcel that has left with nobody named is a parcel nobody
                // can chase. This is the question the return chain is answered from later.
                required: true,
                hint: 'تُسجَّل على الطلبية، ويبقى اسمها فيها ولو حُذفت الشركة لاحقاً',
                // The id crosses back; the name is what the button says. Both, or neither: an
                // id the app cannot name is an answer nobody on the screen agreed to.
                value: $preferred !== null ? (string) $preferred->getKey() : null,
                valueLabel: $preferred?->name,
            );

            $fields[] = TransitionField::text(
                key: 'courier_phone',
                label: 'هاتف المندوب',
                // The company is answerable; the driver is merely reachable, and often nobody
                // has his number at the moment the parcel goes out.
                hint: 'رقم المندوب الذي أخذ الطلبية، إن توفّر',
            );
        }

        // **No parcel weight is asked for here any more.** `orders.weight_kg` was written by
        // this move and read by nothing that computed anything: not the invoice, which is built
        // from the line quantities; not the carrier; not costing. It was demanded of every
        // kilo-priced order on the grounds that «الوزن هو ما تُحاسب عليه», which was never true
        // of the code. What comes off the shelf is asked for below instead — per line, and only
        // where nobody could work it out.
        // **Two moves ask the same two questions, because two moves can be the one where stock
        // leaves.** «جاهزة للطباعة» is where the warehouse weighs the goods and lets them go, and
        // it is the first stop for every printed order. An order of ready-made goods never passes
        // through it — see {@see \App\Domain\Order\Enums\OrderFlow} — so for that one «جاهزة» is
        // still the first and only deduction, and asks in full.
        //
        // **Which of the two this is, is read from `stock_deducted_at` rather than from the
        // road.** That is the same fact `ChangeOrderStatus` decides the deduction itself on, so
        // the form and the deduction cannot disagree about whether this move takes stock out — and
        // a third road added later needs no new branch here.
        //
        // **And an order whose goods a vendor made is asked neither question.** Nothing of ours
        // is on a shelf for it, so «جاهزة» there records that the vendor handed the job over.
        // Asked of the flow, exactly as `ChangeOrderStatus` asks it — the form and the deduction
        // read one fact, so a warehouse picker can never appear over a move that will not use it.
        if (($target === OrderStatus::ReadyToPrint || $target === OrderStatus::Ready)
            && $order->production_flow->deductsStock()) {
            // `items.product` for the labels, and `items.variant.stockItem` because the unit a
            // line is stocked in now lives on the shelf — see {@see OrderItem::stockUnit()}.
            // Asked for once here rather than three times below, and eagerly because strict mode
            // turns a forgotten load into an exception rather than a query per line.
            $order->loadMissing(['items.product', 'items.variant.stockItem']);

            $deductsHere = $order->stock_deducted_at === null;

            // Where the stock this order consumes comes out of, asked exactly once per order —
            // see {@see \App\Domain\Order\Actions\DeductOrderStock}.
            //
            // **Withheld entirely once the stock has gone**, rather than offered-but-optional as
            // it used to be. An order arriving at «جاهزة» already deducted is being *corrected*,
            // and a correction goes back to the shelf it came off — `orders.fulfillment_warehouse_id`
            // — so a second picker here could only ever send the difference somewhere else and
            // leave two shelves wrong instead of one.
            if ($deductsHere) {
                $fields[] = TransitionField::warehouse(
                    key: 'warehouse_id',
                    label: 'المخزن',
                    required: true,
                    hint: self::deductionPreview($order),
                );
            }

            // **What actually comes off the shelf, for the lines where nobody could work it
            // out.** A run sold by the piece and stocked by the kilo has two figures and no
            // conversion between them — see {@see OrderItem::isStockedInAnotherUnit()} — so
            // the person who has the parcel in front of them is asked, in the shelf's unit,
            // once per line that needs it. Every other line is silent: what was sold is what
            // leaves, and a box asking a foreman to retype a number the order already holds can
            // only ever introduce a difference between the two.
            //
            // **Asked again at «جاهزة», and that is the point of asking twice.** The warehouse
            // weighs what it pulls; the press knows what it actually used. The second figure is
            // the true one, and the shelf is corrected to it — see
            // {@see \App\Domain\Order\Actions\RestateOrderStockDeduction}. It opens holding the
            // first figure, so leaving the box alone means «ما زال صحيحاً» rather than silence.
            foreach ($order->items as $item) {
                if (! $item->isStockedInAnotherUnit()) {
                    continue;
                }

                $fields[] = TransitionField::number(
                    key: self::stockQuantityKey($item),
                    label: "المخصوم من {$item->variant_label} ({$item->stockUnit()->label()})",
                    // Required either way: on the first pass nothing may leave unmeasured, and on
                    // the correction an emptied box would read as «صفر» against a shelf that has
                    // already given the goods up.
                    required: true,
                    hint: $deductsHere
                        ? 'المباع '.DecimalText::trim((string) $item->quantity)." {$item->pricing_unit->label()} — والمخزن يُنقص بال{$item->stockUnit()->label()}"
                        : 'خرج من المخزن '.DecimalText::trim((string) $item->warehouse_quantity)." {$item->stockUnit()->label()} — صحّحه إن اختلف المستهلك فعلاً",
                    // An answer, not a placeholder: a line weighed at «جاهزة للطباعة» opens here
                    // holding that figure, so an unchanged run is confirmed by leaving it be.
                    value: $item->warehouse_quantity !== null ? (string) $item->warehouse_quantity : null,
                );
            }
        }

        // «كم الناقص» is meaningless asked of an order: it is a question about a *size*. One
        // field per line, each in that line's own unit and bounded by what was ordered of it —
        // so the app draws the whole thing without knowing what an order line is.
        if ($target === OrderStatus::Shortage) {
            // **What the shelves hold, read once for the whole form.** Without this the hint
            // below would query per line, and `shouldBeStrict()` would be right to object.
            $onHand = self::onHand($order);

            foreach ($order->items as $item) {
                $fields[] = TransitionField::number(
                    key: "shortage_{$item->getKey()}",
                    label: "الناقص من {$item->variant_label} ({$item->pricing_unit->label()})",
                    // Every line optional, and at least one of them insisted on by the domain:
                    // most shortages are one size out of several, and marking the whole form
                    // required would have staff typing zeros to get past it.
                    max: (float) $item->quantity,
                    hint: self::shortageHint($item, $onHand[(int) $item->getKey()] ?? null),
                );
            }
        }

        // **Leaving «نواقص» is the same question from the other end.** The stock arrived, and
        // what arrived of it is the number the person holding the delivery note has — so that is
        // what is asked, rather than making them subtract to reach what is left.
        //
        // **Pre-filled with the whole shortage**, because leaving this status nearly always
        // means all of it came: the common answer is a tap, and typing is for the exception. And
        // only the lines that are actually short are asked about — a form listing every size of
        // a five-line order to ask about the one that was missing is a form to be scrolled past.
        //
        // Not offered on the way to «إلغاء تام»: an order written off while short keeps the
        // record of what it was short of.
        if ($order->status === OrderStatus::Shortage && ! $target->isFinal()) {
            foreach ($order->items as $item) {
                if ($item->shortage_quantity === null || bccomp((string) $item->shortage_quantity, '0', 3) <= 0) {
                    continue;
                }

                $fields[] = TransitionField::number(
                    key: "received_{$item->getKey()}",
                    label: "الواصل من نواقص {$item->variant_label} ({$item->pricing_unit->label()})",
                    max: (float) $item->shortage_quantity,
                    hint: 'الناقص '.DecimalText::trim((string) $item->shortage_quantity).' — ما يبقى منه يُخصم من الفاتورة',
                    value: (string) $item->shortage_quantity,
                );
            }
        }

        // **«كم أخذ العميل فعلاً؟»** — asked at the one moment the answer exists, and of the one
        // person who has it. A customer who takes three hundred of five hundred is an ordinary
        // thing at a counter, and until this existed the order was marked delivered in full and
        // the difference was argued about afterwards with nothing written down.
        //
        // **The boxes are withheld, never the move** — see
        // {@see PermissionName::RecordPartialDelivery}. Somebody without that grant sees
        // «تم الاستلام» exactly as they did before and hands the whole order over, which is the
        // same shape {@see money()} below uses to keep a driver away from the till without
        // keeping them away from the parcel. Null — a console command, an importer — is nobody,
        // and gets no boxes.
        if ($target === OrderStatus::Delivered
            && $actor?->can(PermissionName::RecordPartialDelivery->value)) {
            array_push($fields, ...self::partialDelivery($order));
        }

        // What was just handed over, and how.
        //
        // **The money box, at the two moments money actually appears.** The customer collecting
        // at the counter pays there; the courier's takings come back at settlement. Until this
        // existed, the person holding the cash moved the order, left the screen, opened
        // «الدفعات» and typed the figure a second time — and skipping the second step is how
        // orders ended up closed with nothing recorded against them.
        //
        // It replaced «المبلغ المستلم» (`collected_amount`) on «تم التسوية» rather than sitting
        // beside it. That field asked this same question and answered none of it: it wrote a
        // column no total added up, so an order could carry «المدفوع ٥٠٠» and «المستلم فعلياً
        // ٤٥٠» at once and nothing could say which was true. What it was for — «أيّ الطلبيات رجع
        // مالها ناقصاً» — the ledger now answers exactly, and the column stays in the database
        // for the orders written before this.
        array_push($fields, ...self::deposit($order, $target));

        array_push($fields, ...self::money($order, $target, $actor));

        // **A note travels with every move, and only a cancellation is made to justify itself.**
        // One field either way: the same input, renamed and made required where an explanation
        // is owed. The app has one way to draw it, and the timeline has one place to read it —
        // `order_status_transitions.reason`, which was nullable and waiting for exactly this.
        //
        // `requires_reason` stays on the transition beside it for clients written before fields
        // existed.
        $mustExplain = $target->requiresReason();

        $fields[] = TransitionField::text(
            key: 'reason',
            label: $mustExplain ? 'السبب' : 'ملاحظة',
            required: $mustExplain,
            multiline: true,
            hint: 'تُسجَّل في سجل الطلبية',
        );

        return $fields;
    }

    /**
     * What «انتظار العربون» asks: how much, and how it is expected to arrive.
     *
     * **Neither field touches the ledger, and that is the whole distinction.** The pair below
     * records money that has just been handed over; this pair records money the shop is waiting
     * for — an arrangement, not a payment. Writing it into `order_payments` would put an entry
     * nobody made into a log built to be believed (PAYMENTS-DESIGN §١٠), and every total that
     * reads «المدفوع» would start counting promises.
     *
     * **No permission gate, unlike {@see money()}.** Naming the عربون *is* the move — an order
     * parked in «انتظار العربون» with no figure on it says only «موقوفة»، and the next person to
     * open it has no way to learn what was agreed. So whoever may make the move answers both,
     * and the amount is required rather than optional.
     *
     * **The ceiling is the invoice**, not the remainder: a عربون is part of the order's own
     * price, and asking for more than the whole of it is a typo every time. There is deliberately
     * no floor beyond «أكبر من صفر» — what fraction the shop asks for is the shop's business.
     * **And an invoice of nothing is asked nothing**, rather than being asked for a figure
     * between 0.01 and 0 — see the guard below.
     *
     * Re-entered after a walk back from «عربون مدفوع», the boxes open holding what was agreed
     * last time: the commonest reason to be back here is that the money did not arrive, not that
     * the arrangement changed.
     *
     * @return list<TransitionField>
     */
    private static function deposit(Order $order, OrderStatus $target): array
    {
        if ($target !== OrderStatus::AwaitingDeposit) {
            return [];
        }

        // **A طلبية بلا قيمة is asked nothing at all.** A discount that swallowed the invoice
        // leaves an order whose عربون can only be zero — and the box below would then carry a
        // floor of 0.01 over a ceiling of 0, a required field with no answer that passes. The
        // road itself stays open: «عربون مدفوع» is where the warehouse takes its work from, so
        // an order that cannot reach it is an order nobody there will ever see. What it parks
        // with is written by {@see ChangeOrderStatus}, which puts the zero on the order rather
        // than asking a person to type the only number there is.
        if (bccomp((string) $order->grand_total, '0', Money::SCALE) <= 0) {
            return [];
        }

        $agreed = $order->deposit_expected_amount !== null
            ? Money::normalize($order->deposit_expected_amount)
            : null;

        return [
            TransitionField::number(
                key: self::DEPOSIT_AMOUNT,
                label: 'قيمة العربون',
                required: true,
                // Above zero: an order waiting on a عربون of nothing is an order waiting on
                // nothing, and the move that says so is the wrong one to have made.
                min: 0.01,
                max: (float) (string) $order->grand_total,
                hint: "قيمة تقديرية — إجمالي الطلبية {$order->grand_total}",
                value: $agreed,
            ),
            TransitionField::paymentMethod(
                key: self::DEPOSIT_METHOD,
                label: 'وسيلة دفع العربون',
                // All four, and no receipt beside them: nothing has been paid yet, so there is
                // no slip to attach. The proof is asked for when the money actually arrives —
                // see {@see money()}, where «حوالة» obliges «الواصل».
                methods: PaymentMethod::cases(),
                requiredWith: self::DEPOSIT_AMOUNT,
                hint: 'الطريقة المتوقَّعة — وتُفتح عليها خانة «طريقة الدفع» يوم يُقبض العربون',
                value: ($order->deposit_expected_method ?? PaymentMethod::Cash)->value,
            ),
        ];
    }

    /**
     * The pair of fields that turn a status change into a ledger entry, or nothing at all.
     *
     * **Four conditions, and each of them removes a box that could only fail.**
     *
     * - Only «تم الاستلام» and «تم التسوية». Money changes hands when the parcel does and when
     *   the driver's takings come back; a box on «قيد الطباعة» would be a money field on a
     *   screen with no money in it.
     * - Only for somebody holding `orders.payments.record`. The move itself is a different
     *   grant, and a driver who may drop a parcel off is not thereby trusted with the till —
     *   withholding the field rather than the move is the whole reason [$actor] is passed in.
     * - Only while something is owed. `RecordOrderPayment` refuses anything over the remainder,
     *   so on a settled account every possible answer is a 422.
     * All four methods are offered, «حوالة» included. It was left off while this screen could
     * take no files, which made the counter a worse place to record a payment than the payments
     * screen for no reason a person could see — so the file field came instead, and with it the
     * slip from a card machine that nobody had anywhere to put either.
     *
     * @return list<TransitionField>
     */
    private static function money(Order $order, OrderStatus $target, ?User $actor): array
    {
        if ($target !== OrderStatus::Delivered
            && $target !== OrderStatus::Settled
            && $target !== OrderStatus::DepositPaid) {
            return [];
        }

        if (! $actor?->can(PermissionName::RecordOrderPayments->value)) {
            return [];
        }

        $remaining = $order->remainingAmount();

        if (bccomp($remaining, '0', Money::SCALE) <= 0) {
            return [];
        }

        // **Pre-filled at settlement and empty at the counter, because the two moments differ.**
        // Settling *means* the money came back, and nearly always all of it: the box opens
        // holding the remainder and agreeing costs a tap. Handing bags over means no such thing
        // — the customer may pay all of it, some of it, or none — so nothing is suggested.
        $settling = $target === OrderStatus::Settled;

        // **«عربون مدفوع» opens holding the figure that was agreed**, capped like everything else
        // at what is actually owed — an invoice edited downward since can leave the estimate
        // above the debt, and the ledger would refuse the difference. Still not *required*: the
        // move says the customer paid, and the entry may already have been made on the payments
        // screen, or be made there tomorrow when the transfer lands.
        $expected = null;

        if ($target === OrderStatus::DepositPaid && $order->asksForADeposit()) {
            $asked = Money::normalize($order->deposit_expected_amount);

            // bccomp, not min() over floats: these are money, and a comparison that goes through
            // binary floating point is exactly what `decimal` columns exist to avoid.
            $expected = bccomp($asked, $remaining, Money::SCALE) > 0 ? $remaining : $asked;
        }

        return [
            TransitionField::number(
                key: self::PAYMENT_AMOUNT,
                label: $target === OrderStatus::DepositPaid ? 'العربون المقبوض' : 'المبلغ المقبوض',
                // Never required, at either end. An order paid in full when it was taken is
                // handed over with the box left alone, and one settled after the money was
                // recorded from the payments screen needs nothing here either.
                required: false,
                // The ceiling is the debt: an overpayment is refused by the ledger anyway, and
                // being told so at the field beats being told so after the move is attempted.
                max: (float) $remaining,
                // The figure and nothing else. «اتركه فارغاً إن لم يُقبض شيء» said out loud what
                // «(اختياري)» beside the label already says, under a box whose only other line
                // is the one number the person needs.
                //
                // **And every figure in it is trimmed.** «المتبقي 250» is the sentence; «المتبقي
                // 250.000» is the scale of the column it was read out of, which is nobody's
                // business standing at a counter — see {@see DecimalText}.
                hint: $expected !== null
                    ? 'العربون المتفق عليه '.DecimalText::trim($expected)
                        .' — والمتبقي على الطلبية '.DecimalText::trim($remaining)
                    : 'المتبقي '.DecimalText::trim($remaining),
                // The agreed عربون on the way into «عربون مدفوع», the whole debt on the way into
                // «مُسوّاة», and nothing at all on any other move. `TransitionField::number()`
                // trims what it is handed, so this passes the column's own string.
                value: $settling ? $remaining : $expected,
            ),
            TransitionField::paymentMethod(
                key: self::PAYMENT_METHOD,
                label: 'طريقة الدفع',
                methods: PaymentMethod::cases(),
                // Meaningless without an amount and mandatory with one — the ledger takes no
                // entry lacking it.
                requiredWith: self::PAYMENT_AMOUNT,
                // Cash, because a counter takes cash. An answer, not a placeholder: agreeing
                // costs no taps and disagreeing costs one. **On «عربون مدفوع» the answer the
                // order already carries wins** — the shop wrote down how it expected the عربون
                // to arrive when it asked for it, and re-asking the same question with a
                // different default invites two records of one arrangement.
                value: ($order->deposit_expected_method !== null && $target === OrderStatus::DepositPaid
                    ? $order->deposit_expected_method
                    : PaymentMethod::Cash)->value,
            ),
            // **One field, two jobs.** Obligatory for «حوالة», whose only proof is a document
            // the customer sends — see {@see PaymentMethod::requiresReceipt()} — and offered for
            // everything else, because the slip out of a card machine is worth keeping and
            // somebody holding it should never be told we have nowhere to put it.
            //
            // The condition travels with the field rather than being restated in Dart, so the
            // day a fourth method starts obliging one this screen follows with no release.
            TransitionField::file(
                key: self::PAYMENT_RECEIPT,
                label: 'الواصل',
                // The endpoint's own limits, handed over so the app can refuse a doomed file
                // before pushing it over a mobile connection.
                extensions: (array) config('media.payment_receipts.mimes'),
                maxKilobytes: (int) config('media.payment_receipts.max_kilobytes'),
                requiredIf: [
                    'key' => self::PAYMENT_METHOD,
                    'value' => PaymentMethod::BankTransfer->value,
                ],
                hint: 'مطلوب مع الحوالة، ويُقبل مع غيرها',
            ),
        ];
    }

    /**
     * What the shelves hold of each line's size, keyed by line.
     *
     * **Summed across every warehouse, because at this moment there is no other figure.**
     * «نواقص» is reachable only from «جديد», and `orders.fulfillment_warehouse_id` is not written
     * until the stock actually leaves — so nothing here knows which site the foreman means. The
     * wording in {@see shortageHint()} says «في كل المخازن» for exactly that reason: three hundred
     * spread over three warehouses is not three hundred anybody can pick from one shelf.
     *
     * A line whose size has no shelf behind it is absent from the map, and its hint simply does
     * not mention stock — {@see InventoryService::stockItemFor()}'s named refusal belongs to the
     * deduction, not to a hint on a form.
     *
     * @return array<int, string>
     */
    private static function onHand(Order $order): array
    {
        $order->loadMissing('items.variant.stockItem');

        $shelves = [];

        foreach ($order->items as $item) {
            $shelf = $item->variant?->stockItem;

            if ($shelf !== null) {
                $shelves[(int) $item->getKey()] = (int) $shelf->getKey();
            }
        }

        if ($shelves === []) {
            return [];
        }

        $balances = app(InventoryService::class)->onHandFor(array_values(array_unique($shelves)));

        $byLine = [];

        foreach ($shelves as $lineId => $shelfId) {
            // Absent from the balances map means the size has never been stocked anywhere, which
            // reads to a person as zero — and zero is the useful thing to print here.
            $byLine[$lineId] = $balances[$shelfId] ?? '0.000';
        }

        return $byLine;
    }

    /**
     * «من أصل ٣٠٠ — المتوفر في كل المخازن ٢٧٠ — يُخصم من الفاتورة».
     *
     * **A hint, not a default.** The number is printed for a person to read against what they can
     * see on the shelf; it is not written into the box, because the balance is a record and the
     * shortage is an observation, and the two disagree exactly when this screen matters most —
     * a miscount, breakage, stock promised elsewhere. Pre-filling would turn «كم الناقص؟» into
     * «أكّد ما يقوله النظام», which is the same trade `receive_arrival_sheet` refuses on the
     * purchasing side.
     *
     * The stock half is omitted for a size the warehouse does not carry, rather than printed as
     * zero — «المتوفر ٠» about something that was never stocked is a fact about the catalogue,
     * not about today.
     */
    private static function shortageHint(OrderItem $item, ?string $onHand): string
    {
        // **وكل رقمٍ فيه مشذَّب**، وهي قاعدة `DecimalText` القادمة مع التسليم الجزئي: «من أصل
        // 300» جملة، و«من أصل 300.000» مقياسُ العمود الذي قُرئ منه الرقم، ولا شأن لواقفٍ عند
        // الطاولة به. خيّر الدمجُ بين هذه الدالّة وبين تلميحٍ مشذَّبٍ أبسط، فأُخذ من كلٍّ ما
        // يقوله: بناؤها هي، وتشذيبه هو.
        $hint = 'من أصل '.DecimalText::trim((string) $item->quantity);

        if ($onHand !== null) {
            $hint .= ' — المتوفر في كل المخازن '.DecimalText::trim($onHand)
                .' '.$item->stockUnit()->label();
        }

        return $hint.' — يُخصم من الفاتورة';
    }

    /**
     * The per-line boxes that turn «تم الاستلام» into a record of what was actually handed over.
     *
     * **It asks what was *taken*, not what was left.** The person at the counter is holding the
     * goods they just handed across and counting those; asking for the remainder would make them
     * subtract, which is arithmetic done by the wrong party at the worst moment.
     * {@see RecordPartialDelivery} turns the answer into the remainder on the way in.
     *
     * **Pre-filled with the whole billable quantity**, so the common case — they took all of it —
     * is one tap and an untouched form behaves exactly as this move did before the boxes existed.
     * Capped at the same figure: a customer cannot take more than the order is charging for, and
     * being told so at the field beats being told so after the move is attempted.
     *
     * **A second box only where nobody could work the answer out.** A سادة line goes back on
     * the shelf, and a line sold by the piece and stocked by the kilo has no per-piece weight to
     * convert with — {@see DeductOrderStock} refuses to invent one —
     * so the storekeeper is asked, in the shelf's unit, holding the pro-rata as a figure to
     * correct on the scale. Every other line is silent: where the units agree the conversion is
     * exact, and a printed or وسيط line puts nothing back at all.
     *
     * **And each line says what will become of its leftover**, in its hint, built from the same
     * {@see UndeliveredDisposition::forItem()} the action disposes by — so the sentence on the
     * screen cannot drift from what the button does. That is the rule {@see deductionPreview()}
     * already keeps with `DeductOrderStock`.
     *
     * @return list<TransitionField>
     */
    private static function partialDelivery(Order $order): array
    {
        // `product.productCategory.parent` because the disposition walks it, and
        // `variant.stockItem` because the shelf's unit is read off it. Eagerly, because strict
        // mode turns a forgotten load into an exception rather than a query per line.
        $order->loadMissing(['items.product.productCategory.parent', 'items.variant.stockItem']);

        $fields = [];

        foreach ($order->items as $item) {
            $billable = $item->billableQuantity();

            // A line already charging nothing — wholly short, or wholly left behind on an earlier
            // pass — has nothing to hand over and nothing to ask about.
            if (bccomp($billable, '0', 3) <= 0) {
                continue;
            }

            $disposition = UndeliveredDisposition::forItem($item);

            $fields[] = TransitionField::number(
                key: self::deliveredQuantityKey($item),
                label: "المُستلَم من {$item->variant_label} ({$item->pricing_unit->label()})",
                // Never required: the pre-filled value *is* the answer for almost every order,
                // and a form insisting on a number somebody has to retype to agree with is a
                // form that teaches people to stop reading it.
                required: false,
                max: (float) $billable,
                hint: sprintf(
                    'من أصل %s — وما لا يأخذه يُخصم من الفاتورة و%s',
                    DecimalText::trim($billable),
                    $disposition->returnsToStock() ? 'يعود إلى المخزن' : 'يُسجّل خسارة',
                ),
                value: $billable,
            );

            if (! $disposition->returnsToStock() || ! $item->isStockedInAnotherUnit()) {
                continue;
            }

            $fields[] = TransitionField::number(
                key: self::returnedQuantityKey($item),
                label: "المُعاد إلى المخزن من {$item->variant_label} ({$item->stockUnit()->label()})",
                // Optional, and the reason is that it is meaningless on its own: a value here
                // with a full delivery beside it describes goods nobody left behind. The action
                // ignores it unless the line has a remainder.
                required: false,
                max: (float) $item->producedQuantity(),
                hint: 'خرج من المخزن '.DecimalText::trim($item->producedQuantity())." {$item->stockUnit()->label()} — صحّح المُعاد إن وزنته",
            );
        }

        return $fields;
    }

    /** What the «what did they take» box for one line is called in the payload. */
    public static function deliveredQuantityKey(OrderItem $item): string
    {
        return "delivered_{$item->getKey()}";
    }

    /** What the «what went back on the shelf» box for one line is called in the payload. */
    public static function returnedQuantityKey(OrderItem $item): string
    {
        return "returned_{$item->getKey()}";
    }

    /** What the per-line box for one line is called in the payload. */
    public static function stockQuantityKey(OrderItem $item): string
    {
        return "warehouse_quantity_{$item->getKey()}";
    }

    /** What the «what are we charging for this» box for one line is called in the payload. */
    public static function unitPriceKey(OrderItem $item): string
    {
        return "unit_price_{$item->getKey()}";
    }

    /**
     * «يُخصم منه…» said with the actual figures, one line per size.
     *
     * **The person naming the warehouse could not see what was about to leave it.** What is
     * deducted is {@see OrderItem::producedQuantity()} — `warehouse_quantity ?? quantity` — in
     * the *product's* `stock_unit`, and since that unit became settable it need not be the unit
     * the line is sold in. So an order for 300 bags can take 12.5 kilograms off the shelf, and
     * neither number nor unit was anywhere on the screen that asked which shelf.
     *
     * **A line nobody has weighed yet is named, not numbered.** `producedQuantity()` falls back
     * to the sold quantity, which for a line stocked in another unit is a piece count wearing a
     * kilogram label — «٥٠٠ كجم» for five hundred bags. That fallback is the bug the box
     * below this hint exists to close, and printing it here would be the same lie in the same
     * breath as the question that fixes it.
     *
     * **A hint rather than a field, because it is not an input.** The app renders whatever the
     * server hands it — see {@see OrderResource} — so this
     * reaches every client with no release, and cannot drift from what `DeductOrderStock` will
     * actually do because both read the same accessor.
     *
     * Falls back to the bare sentence for an order with no lines: a heading introducing an empty
     * list reads as a bug.
     */
    private static function deductionPreview(Order $order): string
    {
        $lines = $order->items
            ->map(fn (OrderItem $item): string => sprintf(
                '• %s — %s',
                $item->variant_label,
                $item->isStockedInAnotherUnit() && $item->warehouse_quantity === null
                    ? "بال{$item->stockUnit()->label()}، حسب ما تُدخله أدناه"
                    : DecimalText::trim($item->producedQuantity())." {$item->stockUnit()->label()}",
            ))
            ->all();

        if ($lines === []) {
            return 'يُخصم منه ما تستهلكه هذه الطلبية من المخزون';
        }

        return "يُخصم منه ما تستهلكه هذه الطلبية من المخزون:\n".implode("\n", $lines);
    }
}
