<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The warehouse picker's listing.
 */
final class WarehouseListQuery
{
    /**
     * @return LengthAwarePaginator<int, Warehouse>
     */
    public function __invoke(WarehouseFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return Warehouse::query()
            // Counted, not loaded: a warehouse holding four hundred sizes should not load them
            // to tell a list row how many there are.
            ->withCount('stocks')
            ->when($filters->search !== null, function ($query) use ($filters) {
                $term = '%'.$filters->search.'%';

                // Grouped so the OR set cannot escape and swallow the type filter.
                $query->where(function ($query) use ($term) {
                    $query->where('name', 'ilike', $term)
                        ->orWhere('location', 'ilike', $term);
                });
            })
            ->when($filters->type !== null, fn ($q) => $q->where('type', $filters->type))
            // By id, like cities: insertion order is the business's order — the main store was
            // created first and belongs at the top of the picker. Sorting Arabic names
            // alphabetically would bury it and depends on the database's collation.
            ->orderBy('id')
            ->paginate($perPage);
    }
}
