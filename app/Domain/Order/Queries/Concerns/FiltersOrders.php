<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries\Concerns;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\OrderFilters;
use App\Domain\Order\Queries\OrderSearchKind;
use App\Domain\Order\Queries\OrderSearchTerm;
use App\Domain\Order\Support\PaymentStatusExpression;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filters, written once, for the two queries that need to agree about them.
 *
 * The list and the per-status counts beside it are two questions about the same set. If the
 * count said «جاهزة ٧» while the list showed four, the number would be worse than not being
 * there — so they cannot be allowed to apply a search differently, and the only way to
 * guarantee that is for there to be one implementation.
 *
 * The status filter is the one thing they *must* differ on: the list narrows to the chosen
 * status, while the counts have to see every status to be able to count them. Hence the flag
 * rather than two copies.
 */
trait FiltersOrders
{
    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private function applyFilters(Builder $query, OrderFilters $filters, bool $withStatus = true): Builder
    {
        return $query
            // **Which of the two lists this is, and it is applied first because it decides the
            // set everything below narrows.** `onlyTrashed()` here rather than in one of the
            // three queries that seed their own `Order::query()`: the list, the status counts and
            // the payment-state counts all pass through this method, so the archive cannot end up
            // with archived rows under live numbers. See {@see OrderFilters::$archived}.
            ->when($filters->archived, fn (Builder $q) => $q->onlyTrashed())
            ->when(
                $filters->search !== null,
                fn (Builder $q) => $this->applySearch($q, OrderSearchTerm::from($filters->search)),
            )
            ->when(
                $withStatus && $filters->statuses !== null,
                fn (Builder $q) => $q->whereIn(
                    'status',
                    array_map(fn (OrderStatus $s) => $s->value, $filters->statuses),
                ),
            )
            ->when(
                $filters->paymentStatuses !== null,
                fn (Builder $q) => $this->applyPaymentStatuses($q, $filters->paymentStatuses),
            )
            // **A third axis, crossing the other two rather than narrowing one of them.** «الجاهزة
            // والمستعجلة» is one question, so this sits beside the status the way the payment
            // state does. `false` is a question in its own right — «أرِني ما ليس مستعجلاً» — which
            // is why it is compared against rather than treated as «no filter»; see
            // OrderFilters::boolOrNull().
            ->when($filters->isUrgent !== null, fn (Builder $q) => $q->where('is_urgent', $filters->isUrgent))
            // **A fourth axis, and the one place a filter means more than its column.** See
            // {@see applyReadyMessage()} — and note it is written once, here, so the box's own
            // number and the screen that box opens cannot describe two different sets.
            ->when(
                $filters->readyMessageSent !== null,
                fn (Builder $q) => $this->applyReadyMessage($q, $filters->readyMessageSent),
            )
            ->when($filters->customerId !== null, fn (Builder $q) => $q->where('customer_id', $filters->customerId))
            ->when($filters->cityId !== null, fn (Builder $q) => $q->where('city_id', $filters->cityId))
            // **`placed_at`, in the shop's own timezone, and both of those matter.**
            //
            // The column, because that is what «طلبات اليوم» counts on the home screen — and a
            // card whose number and whose list disagree is worse than either alone. They are the
            // same instant for every order this API takes and part company the day an old one is
            // imported.
            //
            // The timezone, because `whereDate` compares a local date against a UTC column:
            // Libya is two hours ahead, so an order taken at one in the morning is 23:00
            // *yesterday* in UTC and drops out of today — quietly, for the first two hours of
            // every day. The day is turned into a pair of UTC instants instead.
            ->when($filters->from !== null, fn (Builder $q) => $q->where('placed_at', '>=', $this->dayStart($filters->from)))
            ->when($filters->to !== null, fn (Builder $q) => $q->where('placed_at', '<=', $this->dayEnd($filters->to)));
    }

    /**
     * «مَن ينتظر رسالة الجاهزية؟» and «مَن أُرسلت له؟» — and only the second is a test on a column.
     *
     * **The queue is three conditions, not one**, and the two beside the column are what make the
     * answer worth reading:
     *
     * - **It has been ready.** `ready_at` is stamped the first time an order reaches «جاهزة» and
     *   never cleared, so this is «بلغت الجاهزية» rather than «واقفة فيها» — a parcel already out
     *   for delivery whose customer was never told is exactly what this box exists to surface.
     *   Without it the queue would answer with every order in the shop, most of them not made
     *   yet.
     * - **The customer has not already got it.** Telling somebody their bags are ready after they
     *   have collected them is not work anybody is going to do, and a cancelled order has nobody
     *   to tell. This is also what keeps the queue from opening on the whole history the day the
     *   column ships: every order finished before it existed is null, and nearly all of them are
     *   closed.
     *
     * The statuses are read from the enum rather than listed as strings, so a status that becomes
     * an ending later joins this predicate by changing {@see OrderStatus::isClosed()} alone.
     *
     * @param  Builder<Order>  $query
     */
    private function applyReadyMessage(Builder $query, bool $sent): void
    {
        if ($sent) {
            $query->whereNotNull('ready_message_sent_at');

            return;
        }

        $closed = array_values(array_map(
            fn (OrderStatus $status) => $status->value,
            array_filter(OrderStatus::cases(), fn (OrderStatus $status) => $status->isClosed()),
        ));

        $query->whereNull('ready_message_sent_at')
            ->whereNotNull('ready_at')
            ->whereNotIn('status', $closed);
    }

    /**
     * Narrows to orders standing in any of the given payment states.
     *
     * **There is no `payment_status` column to compare against** — see {@see PaymentStatus} for
     * why storing one would rot the first time an order's total moved — so the state is computed
     * in SQL by {@see PaymentStatusExpression}, which the counts query groups by as well. One
     * expression, two callers, no chance of the list and the number beside it disagreeing.
     *
     * @param  Builder<Order>  $query
     * @param  list<PaymentStatus>  $statuses
     */
    private function applyPaymentStatuses(Builder $query, array $statuses): void
    {
        $wires = array_map(fn (PaymentStatus $status) => $status->value, $statuses);
        $placeholders = implode(', ', array_fill(0, count($wires), '?'));

        // Bound, never interpolated: the values come from an enum here, and a raw fragment that
        // is safe only because of where today's caller happens to get its input is a habit that
        // outlives the caller.
        $query->whereRaw('('.PaymentStatusExpression::sql().") IN ({$placeholders})", $wires);
    }

    /** The first instant of that local day, as UTC. */
    private function dayStart(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, $this->businessTimezone())->startOfDay()->utc();
    }

    /** The last instant of that local day, as UTC. */
    private function dayEnd(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, $this->businessTimezone())->endOfDay()->utc();
    }

    private function businessTimezone(): string
    {
        return (string) config('app.business_timezone', 'Africa/Tripoli');
    }

    /**
     * One box, one column — decided by the shape of what was typed.
     *
     * **Not an OR across every column, which is what this replaces.** The old query matched a
     * term anywhere in the order code, the tracking number, the customer's name, their phone and
     * their code at once, so `52` returned the order numbered 52 alongside every customer whose
     * phone happened to contain those digits. A search that answers a question nobody asked is a
     * search people stop trusting.
     *
     * Each kind is matched the way that kind is meant. A phone is a **prefix** — people type as
     * much of it as they remember, and `0912` should be narrowing the list, not failing. An
     * order number and a customer code are **exact**: «طلبية رقم ٥٢» means that one, and
     * returning 52, 520 and 521 beside it is noise the reader has to filter by eye.
     *
     * @param  Builder<Order>  $query
     */
    private function applySearch(Builder $query, OrderSearchTerm $term): void
    {
        match ($term->kind) {
            OrderSearchKind::OrderCode => $query->where('code', $term->value),

            OrderSearchKind::Phone => $query->whereHas(
                'customer',
                fn ($q) => $q->where('phone', 'like', $term->value.'%'),
            ),

            OrderSearchKind::CustomerCode => $query->whereHas(
                'customer',
                fn ($q) => $q->where('code', $term->value),
            ),

            // `ilike`, because Postgres `like` is case-sensitive and a name is not a code.
            OrderSearchKind::Name => $query->whereHas(
                'customer',
                fn ($q) => $q->where('name', 'ilike', '%'.$term->value.'%'),
            ),
        };
    }
}
