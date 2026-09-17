<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries;

use App\Domain\Order\Enums\PaymentStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\Concerns\FiltersOrders;
use App\Domain\Order\Support\PaymentStatusExpression;
use Illuminate\Support\Facades\DB;

/**
 * How many orders stand unpaid, part-paid, paid and overpaid right now.
 *
 * The number beside each row of the payment filter, and the figures the home screen opens on.
 * Without it the filter is a list of words, and learning that nothing is unpaid costs a tap, a
 * request and an empty screen — the same reasoning that put counts beside the status filter.
 *
 * **Grouped by {@see PaymentStatusExpression}, the same expression the filter narrows with.**
 * That is what stops «غير مدفوعة ٧» sitting above a list of four: one statement of the rule in
 * SQL, read by both.
 *
 * **Every state is present, zeros included** — a missing key leaves a client choosing between a
 * blank and a zero, and those mean different things.
 *
 * **The payment filter is deliberately not applied**, exactly as {@see OrderStatusCountsQuery}
 * drops the status one: counts narrowed to the state already chosen would every one of them
 * equal the list's own length. Every other filter *is* applied, so the numbers describe the set
 * the user is actually looking at.
 *
 * **«Every other filter» is carried by {@see OrderFilters::withoutPaymentStatuses()}, which names
 * each one by hand** — so this is the query a field forgotten there breaks, and it breaks
 * quietly: these chips would count the live orders while the status chips beside them counted the
 * archive, and nothing on the screen would say the two rows are about different sets.
 */
final class OrderPaymentStatusCountsQuery
{
    use FiltersOrders;

    /**
     * @return array<string, int>
     */
    public function __invoke(OrderFilters $filters): array
    {
        $expression = PaymentStatusExpression::sql();

        $tallied = $this->applyFilters(Order::query(), $filters->withoutPaymentStatuses())
            ->selectRaw("({$expression}) as payment_state, count(*) as aggregate")
            ->groupBy(DB::raw($expression))
            ->pluck('aggregate', 'payment_state');

        $counts = [];

        foreach (PaymentStatus::cases() as $status) {
            $counts[$status->value] = (int) ($tallied[$status->value] ?? 0);
        }

        return $counts;
    }
}
