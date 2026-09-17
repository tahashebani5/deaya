<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

use App\Domain\Inventory\Models\StockItemGroup;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The material picker's listing, and the management screen's.
 *
 * Carries two counts rather than the rows behind them: how many sizes the material comes in, and
 * how many products are made of it. Both are what the screen actually shows, and loading either
 * to count it would be a query per row.
 */
final class StockItemGroupListQuery
{
    /**
     * @return LengthAwarePaginator<int, StockItemGroup>
     */
    public function __invoke(StockItemGroupFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return StockItemGroup::query()
            ->withCount(['items', 'products'])
            ->when(
                $filters->search !== null,
                fn (Builder $query) => $query->where('name', 'ilike', '%'.$filters->search.'%'),
            )
            ->when(
                $filters->isActive !== null,
                fn (Builder $query) => $query->where('is_active', $filters->isActive),
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($perPage);
    }
}
