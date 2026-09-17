<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

use App\Domain\Inventory\Models\StockItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The shelf picker's listing, and the management screen's.
 *
 * One query for both — they ask the same question and differ only by which rows they want.
 *
 * Ordered by name before size so every «كيس شحن» sits together and its sizes read in order
 * underneath, which is how a storekeeper looks for one. `sort_order` overrides that for the few
 * items somebody has deliberately pinned.
 */
final class StockItemListQuery
{
    /**
     * @return LengthAwarePaginator<int, StockItem>
     */
    public function __invoke(StockItemFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return StockItem::query()
            // Counted, not loaded: the screen says how many sizes draw on each shelf, and
            // loading them to count them would be a query per row.
            ->withCount('variants')
            ->when(
                $filters->search !== null,
                fn (Builder $query) => $query->where('name', 'ilike', '%'.$filters->search.'%'),
            )
            ->when(
                $filters->isActive !== null,
                fn (Builder $query) => $query->where('is_active', $filters->isActive),
            )
            ->when(
                $filters->widthCm !== null,
                fn (Builder $query) => $query->where('width_cm', $filters->widthCm),
            )
            ->when(
                $filters->heightCm !== null,
                fn (Builder $query) => $query->where('height_cm', $filters->heightCm),
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('width_cm')
            ->orderBy('height_cm')
            ->paginate($perPage);
    }
}
