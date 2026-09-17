<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Queries;

use App\Domain\Shortage\Models\Shortage;
use App\Domain\Shortage\Queries\Concerns\FiltersShortages;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The shortages list: newest first, filtered by status, assignee, product, source and order.
 *
 * **Open work first would be the other reasonable order**, and it is not used: «مكتمل» rows are
 * the majority within a month of shipping, and a list that buries them would make the historical
 * record — the thing §١٠ of the brief asks to keep — reachable only by filtering. Newest first
 * with a status chip row above it answers both.
 *
 * The eager loads are the resource's whole appetite, and `order` is among them because
 * `belongsToAnArchivedOrder()` reads it — without it every row on the page queries once and
 * `shouldBeStrict()` makes that a 500 outside production, which is the point of it.
 */
final class ShortageListQuery
{
    use FiltersShortages;

    /**
     * @return LengthAwarePaginator<int, Shortage>
     */
    public function __invoke(ShortageFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->applyFilters(Shortage::query(), $filters)
            ->with(['order', 'customer', 'product', 'productVariant', 'assignee'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
