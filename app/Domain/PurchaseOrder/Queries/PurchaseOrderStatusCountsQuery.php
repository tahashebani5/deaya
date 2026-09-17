<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Queries;

use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\PurchaseOrder\Queries\Concerns\FiltersPurchaseOrders;

/**
 * How many purchase orders sit in each status right now.
 *
 * The numbers on a supplier's screen: «الجارية ٣», «المكتملة ٩». Without them, the only way to
 * learn that nothing is outstanding with this vendor is to tap through and meet an empty list —
 * a round trip to answer a question that could have come with the screen.
 *
 * **Every status is present, including the zeros.** A missing key would leave the app choosing
 * between drawing a blank and drawing a zero, and the two say different things: zero is an
 * answer, blank is «we did not ask».
 *
 * **One query, not four.** A `GROUP BY` over the filtered set, so a fifth status costs nothing
 * here.
 *
 * Shares its filters with {@see PurchaseOrderListQuery} through {@see FiltersPurchaseOrders},
 * and the status filter is deliberately not applied: counts narrowed to the status already
 * chosen would every one of them read as the list's own length.
 */
final class PurchaseOrderStatusCountsQuery
{
    use FiltersPurchaseOrders;

    /**
     * @return array<string, int>
     */
    public function __invoke(PurchaseOrderFilters $filters): array
    {
        $tallied = $this->applyFilters(PurchaseOrder::query(), $filters, withStatus: false)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [];

        foreach (PurchaseOrderStatus::cases() as $status) {
            $counts[$status->value] = (int) ($tallied[$status->value] ?? 0);
        }

        return $counts;
    }
}
