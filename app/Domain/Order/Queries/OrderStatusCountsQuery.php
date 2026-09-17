<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\Concerns\FiltersOrders;

/**
 * How many orders sit in each status right now.
 *
 * The number beside each row of the status filter. Without it the filter is a list of words and
 * the only way to learn there are no returns today is to tap «رواجع» and find an empty screen —
 * which is a round trip and a disappointment to answer a question the server could have answered
 * with the page.
 *
 * **Every status is present, including the zeros.** A missing key would leave the app deciding
 * between rendering a blank and rendering a zero, and the two mean different things: zero is an
 * answer, blank is "we did not ask".
 *
 * **One query, not twelve.** A `GROUP BY` over the filtered set, so adding a thirteenth status
 * costs nothing here.
 *
 * Shares its filters with {@see OrderListQuery} through {@see FiltersOrders}, and the status
 * filter is deliberately *not* applied: counts that only counted the status already chosen would
 * every one of them read as the list's own length.
 *
 * **The archive included.** It seeds its own `Order::query()`, so whether these numbers describe
 * the live orders or the deleted ones is decided by {@see OrderFilters::$archived} rather than
 * here — which is what keeps this count and the list it sits beside talking about one set.
 */
final class OrderStatusCountsQuery
{
    use FiltersOrders;

    /**
     * @return array<string, int>
     */
    public function __invoke(OrderFilters $filters): array
    {
        $tallied = $this->applyFilters(Order::query(), $filters, withStatus: false)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [];

        foreach (OrderStatus::cases() as $status) {
            $counts[$status->value] = (int) ($tallied[$status->value] ?? 0);
        }

        return $counts;
    }
}
