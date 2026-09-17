<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Queries\Concerns;

use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\PurchaseOrder\Queries\PurchaseOrderFilters;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filters, written once, for the two queries that have to agree about them.
 *
 * The list and the counts beside it are two questions about one set — a supplier's screen reads
 * «الجارية ٣» from the second and opens the first to show them. A number that disagreed with
 * the screen it opens is worse than no number, and the only way to guarantee they cannot
 * disagree is one implementation. Same reasoning, same shape as {@see FiltersOrders}.
 *
 * The status filter is the one thing they must differ on: the list narrows to the chosen
 * statuses, while the counts have to see every status to count it. Hence the flag rather than
 * two copies.
 */
trait FiltersPurchaseOrders
{
    /**
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
     */
    private function applyFilters(
        Builder $query,
        PurchaseOrderFilters $filters,
        bool $withStatus = true,
    ): Builder {
        return $query
            ->when($filters->vendorId !== null, fn (Builder $q) => $q->where('vendor_id', $filters->vendorId))
            ->when($filters->warehouseId !== null, fn (Builder $q) => $q->where('warehouse_id', $filters->warehouseId))
            ->when(
                $filters->search !== null,
                fn (Builder $q) => $this->applySearch($q, $filters->search),
            )
            ->when(
                $withStatus && $filters->statuses !== null,
                fn (Builder $q) => $q->whereIn(
                    'status',
                    array_map(fn (PurchaseOrderStatus $status) => $status->value, $filters->statuses),
                ),
            );
    }

    /**
     * What somebody types when they are looking for a purchase order.
     *
     * **The two names on the card, plus the id.** A purchase order has no code and no title of
     * its own — the card is a vendor, a warehouse and a date — so those names are the only text
     * there is to match. The id is added because the detail screen calls it «أمر شراء #12», and a
     * number typed after reading that has to find it.
     *
     * The id is matched only when the term *is* a number: `whereRaw` casting every row's id to
     * text would drop the index for the sake of a case that cannot match anyway.
     *
     * Grouped so the OR set cannot escape and swallow the status filter beside it — the same trap
     * {@see VendorListQuery} documents.
     *
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
     */
    private function applySearch(Builder $query, string $search): Builder
    {
        $term = '%'.$search.'%';

        return $query->where(function (Builder $query) use ($search, $term): void {
            $query
                ->whereHas('vendor', fn (Builder $q) => $q->where('name', 'ilike', $term))
                ->orWhereHas('warehouse', fn (Builder $q) => $q->where('name', 'ilike', $term));

            if (ctype_digit($search)) {
                $query->orWhere('id', (int) $search);
            }
        });
    }
}
