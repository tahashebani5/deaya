<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Queries;

use App\Domain\Delivery\Models\City;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The city picker's listing.
 */
final class CityListQuery
{
    /**
     * @return LengthAwarePaginator<int, City>
     */
    public function __invoke(CityFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return City::query()
            // Counted, not loaded: the list shows how many neighbourhoods a city has, and
            // loading all 61 of مصراتة's just to count them would be a query per row.
            ->withCount('regions')
            ->when($filters->search !== null, function ($query) use ($filters) {
                $term = '%'.$filters->search.'%';

                // Grouped so the OR set cannot escape and swallow the other filters.
                $query->where(function ($query) use ($term) {
                    $query->where('name', 'ilike', $term)
                        ->orWhere('darb_branch', 'ilike', $term);
                });
            })
            ->when(
                $filters->isRegionRequired !== null,
                fn ($q) => $q->where('is_region_required', $filters->isRegionRequired),
            )
            ->when(
                $filters->hasPrice !== null,
                fn ($q) => $filters->hasPrice
                    ? $q->whereNotNull('delivery_price')
                    : $q->whereNull('delivery_price'),
            )
            // By id, not by name: insertion order *is* the business's order — the office-pickup
            // branches were seeded first and belong at the top of the picker. Sorting Arabic
            // names alphabetically would bury them and depends on the database's collation.
            ->orderBy('id')
            ->paginate($perPage);
    }
}
