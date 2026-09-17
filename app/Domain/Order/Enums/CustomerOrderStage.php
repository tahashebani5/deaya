<?php

declare(strict_types=1);

namespace App\Domain\Order\Enums;

/**
 * Where an order is, said in words a customer can act on.
 *
 * **Nineteen statuses become eight stages, and the collapsing happens here rather than in the
 * app.** {@see OrderStatus} is the workshop's vocabulary: «جاهزة للطباعة» is the moment
 * inventory hands a job to the press, «نواقص» is a shelf that came up short. Those are true and
 * they are none of the customer's business — telling somebody their order is «قيد التصنيع»
 * announces that we did not make it ourselves, and «انتظار العربون» on their screen is the shop
 * arguing about money through a status label.
 *
 * What a customer wants from a status is one of two answers: *is anything required of me?* and
 * *roughly how far along is it?* Eight stages answer both; nineteen answer neither.
 *
 * **In the server, because a mapping in the client is a mapping in two places.** The app draws
 * what it is sent, the same rule `label()` follows everywhere else in this codebase — and a
 * status added to the business must not be able to reach a customer's screen as a raw English
 * key nobody translated. {@see forStatus()} is total over `OrderStatus`, so the day a nineteenth
 * status arrives the build fails here rather than the word `manufacturing` appearing on a
 * phone.
 */
enum CustomerOrderStage: string
{
    /** Sent from the app, nobody has looked at it yet. The only stage that is the customer's move to make — by waiting. */
    case UnderReview = 'under_review';

    /** Accepted, and being got ready. Everything the workshop does before the artwork or the press. */
    case Preparing = 'preparing';

    /** The artwork is being agreed — the one stage where the customer may be asked for something. */
    case Designing = 'designing';

    /** Being made, here or at a vendor's bench. The customer is not told which, because it does not change anything for them. */
    case Producing = 'producing';

    /** Made, and waiting to go out or to be collected. */
    case Ready = 'ready';

    /** On its way. */
    case OnTheWay = 'on_the_way';

    /** With the customer. Settlement is the shop's own bookkeeping and is not a stage. */
    case Delivered = 'delivered';

    /** Came back. Why it came back is between the shop and the courier. */
    case Returned = 'returned';

    /** Written off. */
    case Cancelled = 'cancelled';

    /**
     * The shop would not take this request.
     *
     * **Its own stage rather than «ملغاة», because the two are not the same news.** «ملغاة» is an
     * order the shop took and then wrote off; this one was never taken. Folding them would tell a
     * customer their order was cancelled when nothing was ever agreed — and would leave them with
     * no way to tell the difference between "we started and stopped" and "we could not take this".
     */
    case Rejected = 'rejected';

    /**
     * The stage a workshop status reads as.
     *
     * **Total over {@see OrderStatus} on purpose** — no `default` arm. A new status must be
     * given a stage here deliberately, and until it is, `OrderStatusTest`'s sweep and this
     * `match` fail the build. A `default` would quietly file the next status under whatever
     * stage happened to be convenient, which is how a customer ends up watching the wrong word.
     */
    public static function forStatus(OrderStatus $status): self
    {
        return match ($status) {
            OrderStatus::Requested => self::UnderReview,

            // **Five statuses, one stage, and the deposit pair is why it is worth explaining.**
            // «انتظار العربون» and «عربون مدفوع» are a conversation about money that belongs on
            // an invoice, not in a progress bar; «نواقص» is our shelf being short, which is our
            // problem to solve rather than news to break by status label. All five mean the same
            // thing to the person waiting: taken, not yet being made.
            OrderStatus::New,
            OrderStatus::AwaitingDeposit,
            OrderStatus::DepositPaid,
            OrderStatus::ReadyToPrint,
            OrderStatus::Shortage => self::Preparing,

            OrderStatus::Designing => self::Designing,

            // Our press or somebody else's bench — the same wait, and the difference is a
            // commercial arrangement the customer did not buy.
            OrderStatus::Printing,
            OrderStatus::Manufacturing => self::Producing,

            // «استلام مكتب» is an order waiting at the counter, which from the customer's side
            // is exactly «جاهزة» — the app tells them where to come from the fulfilment type,
            // not from the stage.
            OrderStatus::Ready,
            OrderStatus::OfficePickup => self::Ready,

            OrderStatus::OutForDelivery => self::OnTheWay,

            // **«تم التسوية» is not a stage of its own.** Settling is the shop agreeing its own
            // books; for the customer the parcel arrived and that was the end of it.
            OrderStatus::Delivered,
            OrderStatus::Settled => self::Delivered,

            // Which link in the chain it came back from — courier, carrier, our own counter —
            // is an operational fact. To the customer it came back, and «إعادة إرسال» is it
            // going out again, which has not happened yet.
            OrderStatus::ReturnedCourier,
            OrderStatus::ReturnedCarrier,
            OrderStatus::ReturnedOffice,
            OrderStatus::Resend => self::Returned,

            OrderStatus::Cancelled => self::Cancelled,

            OrderStatus::RequestRejected => self::Rejected,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::UnderReview => 'بانتظار المراجعة',
            self::Preparing => 'قيد التجهيز',
            self::Designing => 'قيد التصميم',
            self::Producing => 'قيد الإنتاج',
            self::Ready => 'جاهزة',
            self::OnTheWay => 'جاري التوصيل',
            self::Delivered => 'تم الاستلام',
            self::Returned => 'مرتجعة',
            self::Cancelled => 'ملغاة',
            self::Rejected => 'مرفوضة',
        };
    }

    /**
     * One sentence under the stage, telling the customer what is happening or what is wanted.
     *
     * **Beside `label()` rather than in the app, for the same reason `label()` is here.** The
     * design draws a line under every order card — «نراجع طلبيتك ونؤكّدها خلال ساعات العمل» —
     * and a `switch` over stages written in Dart would be the second copy of this mapping that
     * {@see forStatus()}'s whole doc comment argues against. A stage added to the business gets
     * its sentence here and reaches every installed app without a release.
     *
     * **Two stages return null on purpose.** «تم الاستلام» and «ملغاة» are over; a reassuring
     * line under a finished order is filler, and the design draws none.
     *
     * Nothing here promises a date. «يصلك خلال يومين» is in the mockup and is not said, because
     * this server does not know the courier's schedule and a missed promise made by a status
     * line is worse than no line.
     */
    public function hint(): ?string
    {
        return match ($this) {
            self::UnderReview => 'نراجع طلبيتك ونؤكّدها خلال ساعات العمل',
            self::Preparing => 'طلبيتك مؤكّدة، ونجهّزها الآن',
            self::Designing => 'نعمل على التصميم، وسنعرضه عليك قبل الطباعة',
            self::Producing => 'طلبيتك في الإنتاج',
            self::Ready => 'جاهزة — سنتواصل معك للتسليم',
            self::OnTheWay => 'مع المندوب، في الطريق إليك',
            self::Returned => 'رجعت إلينا، وسنتواصل معك بشأنها',
            // **A sentence, where «ملغاة» gets none.** The other two endings need no line: one
            // arrived and one was written off, and both are self-explanatory. A refusal is not —
            // it leaves the customer with a question, and the one useful thing to say is where to
            // take it. The *reason* travels on the order itself, not here.
            self::Rejected => 'لم نتمكّن من قبول هذا الطلب — تواصل معنا لمعرفة التفاصيل',

            self::Delivered, self::Cancelled => null,
        };
    }

    /**
     * The workshop statuses that read as this stage.
     *
     * **Derived from {@see forStatus()}, never written out a second time.** «قيد التجهيز» is
     * five statuses and «مرتجعة» is four; a hand-kept list here would agree with `forStatus()`
     * on the day it was written and drift the first time a status moves between stages. Walking
     * every status and keeping the ones that map here cannot drift, and it costs a loop over
     * nineteen enum cases.
     *
     * @return list<OrderStatus>
     */
    public function statuses(): array
    {
        return array_values(array_filter(
            OrderStatus::cases(),
            fn (OrderStatus $status): bool => self::forStatus($status) === $this,
        ));
    }

    /** Whether the order is still moving — what the app's «قيد التنفيذ» filter means. */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Delivered, self::Cancelled, self::Rejected], true);
    }
}
