<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Queries\Concerns;

use App\Domain\Shortage\Models\Shortage;
use App\Domain\Shortage\Queries\ShortageFilters;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place a shortage list is narrowed.
 *
 * Shared by the list and the status counts so the chip row and the page beneath it can never
 * describe different sets of rows — the failure ORDER-DELETE-AND-ARCHIVE §٦ documents, where
 * three queries seeded their own builders and one of them drifted.
 */
trait FiltersShortages
{
    /**
     * @param  Builder<Shortage>  $query
     * @return Builder<Shortage>
     */
    protected function applyFilters(Builder $query, ShortageFilters $filters): Builder
    {
        /*
         * **The archive guard, and it is first because it is not a filter.**
         *
         * A shortage carries `order_id` and a customer, so a reader without
         * `orders.archive.view` must not be answered about one whose order has been archived —
         * the back door `ArchivedOrdersNeedTheArchiveGrant` closes in front of `logs.view`,
         * reopened from a new direction. See SHORTAGES-DESIGN §٥.
         *
         * `whereDoesntHave` with `withTrashed()` rather than a join: `Shortage::order()` is
         * declared `withTrashed()`, so an ordinary `whereHas` would match archived orders too and
         * this has to say explicitly which ones it means. Manual shortages have no order at all
         * and are never excluded — `whereDoesntHave` is true for them, which is the answer wanted.
         */
        if (! $filters->includeArchivedOrders) {
            $query->whereDoesntHave('order', function (Builder $order): void {
                $order->whereNotNull('orders.deleted_at');
            });
        }

        if ($filters->statuses !== null) {
            $query->whereIn('status', array_map(
                fn ($status) => $status->value,
                $filters->statuses,
            ));
        }

        // Two different questions, and only one of them is «مُسنَد إلى فلان». «غير مُسنَد» is a
        // queue somebody actually works — it is what the board means by work nobody has picked up.
        if ($filters->unassignedOnly) {
            $query->whereNull('assigned_to_user_id');
        } elseif ($filters->assignedToUserId !== null) {
            $query->where('assigned_to_user_id', $filters->assignedToUserId);
        }

        if ($filters->productId !== null) {
            $query->where('product_id', $filters->productId);
        }

        if ($filters->source !== null) {
            $query->where('source', $filters->source->value);
        }

        if ($filters->orderId !== null) {
            $query->where('order_id', $filters->orderId);
        }

        if ($filters->customerId !== null) {
            $query->where('customer_id', $filters->customerId);
        }

        if ($filters->search !== null) {
            $term = $filters->search;

            $query->where(function (Builder $matches) use ($term): void {
                // `ILIKE` rather than `LIKE`: Postgres is case-sensitive, and a storekeeper
                // typing «كيس» has no way to know how the row was capitalised.
                $matches->where('shortages.name', 'ILIKE', '%'.$term.'%')
                    ->orWhere('shortages.code', 'ILIKE', $term.'%')
                    // The order's own number, because «نواقص طلبية ١٢٠٤» is how somebody looks
                    // for these — and the archive guard above still applies, so this cannot be
                    // used to discover that an archived order exists.
                    ->orWhereHas('order', function (Builder $order) use ($term): void {
                        $order->where('orders.code', 'ILIKE', $term.'%');
                    });
            });
        }

        return $query;
    }
}
