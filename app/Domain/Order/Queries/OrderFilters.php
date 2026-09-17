<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentStatus;
use App\Domain\Order\Queries\Concerns\FiltersOrders;

final readonly class OrderFilters
{
    /**
     * @param  list<OrderStatus>|null  $statuses  more than one, because the work queues staff
     *                                            actually want are groups: "everything still in
     *                                            production", "everything that came back".
     */
    public function __construct(
        /** Matches the order number, the customer's name or their phone. */
        public ?string $search = null,
        public ?array $statuses = null,
        public ?int $customerId = null,
        public ?int $cityId = null,
        public ?string $from = null,
        public ?string $to = null,
        /**
         * @var list<PaymentStatus>|null Repeatable like `status`, and for the same reason: «أرِني
         *                               ما لم يُدفع» in practice means unpaid *and* part-paid,
         *                               and making somebody run the list twice to see one queue
         *                               is how a filter goes unused.
         */
        public ?array $paymentStatuses = null,
        /**
         * Null is «كلاهما» — the list as it has always been. `false` is a question in its own
         * right («أرِني ما ليس مستعجلاً») rather than the absence of one, which is why this is
         * a nullable bool and not a flag.
         */
        public ?bool $isUrgent = null,
        /**
         * «هل أُرسلت رسالة الجاهزية؟» — and the two halves are not mirror images of each other.
         *
         * `true` is a plain test on the column: «أيّها أُبلِغ أصحابها؟».
         *
         * `false` is **the queue**, not the column's negation — it also demands that the order
         * has reached «جاهزة» and has not been delivered, settled or cancelled. A bare
         * `ready_message_sent_at IS NULL` would answer with every order ever taken, including the
         * ones nobody could message yet because nothing is made, and every one the customer
         * already collected; a filter whose answer nobody wants is a filter nobody uses. See §٥
         * of Docs/orders/ORDER-READY-MESSAGE.md.
         *
         * Null is «كلاهما» — the list as it was before this existed.
         */
        public ?bool $readyMessageSent = null,
        /** Which end of the queue the list starts at. Never null: there is always an order. */
        public OrderSort $sort = OrderSort::Newest,
        /**
         * Which of the two lists this is: the live one, or the archive of deleted orders.
         *
         * **A filter rather than a query of its own, and that is the whole reason it is here.**
         * Three queries seed their own `Order::query()` — the list, the status counts and the
         * payment-state counts — so `onlyTrashed()` written into one of them would leave the
         * other two describing a different set, and the screen would show archived rows under
         * live numbers. Carried on the filters, all three inherit it through
         * {@see FiltersOrders}.
         *
         * A bool and not a nullable one: unlike «مستعجلة», there is no «كلاهما» to ask for. A
         * deleted order is not a row the live list is entitled to show, and mixing the two would
         * put an order somebody deleted back in the work queue.
         */
        public bool $archived = false,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromArray(array $query): self
    {
        $search = trim((string) ($query['search'] ?? ''));

        return new self(
            search: $search !== '' ? $search : null,
            statuses: self::statuses($query),
            customerId: self::intOrNull($query['customer_id'] ?? null),
            cityId: self::intOrNull($query['city_id'] ?? null),
            from: self::textOrNull($query['from'] ?? null),
            to: self::textOrNull($query['to'] ?? null),
            paymentStatuses: self::paymentStatuses($query),
            isUrgent: self::boolOrNull($query['urgent'] ?? null),
            // Named on the wire exactly as the column reads on the payload, so «الطلبيات التي لم
            // تُرسل لها رسالة» is one word in both places.
            readyMessageSent: self::boolOrNull($query['is_ready_message_sent'] ?? null),
            sort: OrderSort::fromRequest($query['sort'] ?? null),
            // Read the same way «مستعجلة» is, and then defaulted rather than left null: an
            // unanswered question here means the live list, which is what every caller written
            // before the archive existed was asking for.
            archived: self::boolOrNull($query['archived'] ?? null) ?? false,
        );
    }

    /**
     * The same question with the payment filter dropped.
     *
     * What {@see OrderPaymentStatusCountsQuery} counts against: narrowing the counts to the
     * state already chosen would make every one of them equal the list's own length. Expressed
     * as a copy rather than a flag on `applyFilters()` because it is the *filter* that is being
     * asked a different question here, not the query — the status counts use the flag because
     * they genuinely share this object with the list.
     *
     * **Every field is named by hand below, so a new one has to be added here too.** Left out, it
     * silently reverts to its default for the payment counts alone: the archive would draw its
     * status chips over the deleted orders and its payment chips over the live ones, two rows of
     * numbers describing two different sets, side by side on one screen, with nothing to say so.
     */
    public function withoutPaymentStatuses(): self
    {
        return new self(
            search: $this->search,
            statuses: $this->statuses,
            customerId: $this->customerId,
            cityId: $this->cityId,
            from: $this->from,
            to: $this->to,
            paymentStatuses: null,
            isUrgent: $this->isUrgent,
            readyMessageSent: $this->readyMessageSent,
            sort: $this->sort,
            archived: $this->archived,
        );
    }

    /**
     * Same shape as {@see statuses()}, and unknown values are dropped the same way.
     *
     * @param  array<string, mixed>  $query
     * @return list<PaymentStatus>|null
     */
    private static function paymentStatuses(array $query): ?array
    {
        $raw = $query['payment_status'] ?? null;

        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        $statuses = array_filter(array_map(
            fn (mixed $value) => PaymentStatus::tryFrom((string) $value),
            is_array($raw) ? $raw : [$raw],
        ));

        return $statuses === [] ? null : array_values($statuses);
    }

    /**
     * Accepts `status=ready` and `status[]=ready&status[]=printing` alike, and quietly drops a
     * value that names no status — a filter nobody can satisfy would return an empty page and
     * look like "no orders" rather than "you asked for something that does not exist".
     *
     * @param  array<string, mixed>  $query
     * @return list<OrderStatus>|null
     */
    private static function statuses(array $query): ?array
    {
        $raw = $query['status'] ?? null;

        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        $statuses = array_filter(array_map(
            fn (mixed $value) => OrderStatus::tryFrom((string) $value),
            is_array($raw) ? $raw : [$raw],
        ));

        return $statuses === [] ? null : array_values($statuses);
    }

    /**
     * `1`/`0`, `true`/`false` and `"yes"`/`"no"` all arrive as strings in a query string, so the
     * reading is Laravel's own rather than a cast — `(bool) "0"` is false but `(bool) "false"`
     * is true, which would turn «أرِني ما ليس مستعجلاً» into its opposite.
     *
     * Anything that is neither is treated as «لم يُسأل», for the reason a status naming nothing
     * is dropped: a filter nobody can satisfy returns an empty page and reads as «لا طلبيات».
     */
    private static function boolOrNull(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    private static function textOrNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }
}
