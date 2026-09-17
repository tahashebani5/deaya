<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Queries;

use App\Domain\Delivery\Models\ShippingCompany;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The carriers, for the management screen and for the dispatch picker.
 */
final class ShippingCompanyListQuery
{
    /**
     * @return LengthAwarePaginator<int, ShippingCompany>
     */
    public function __invoke(ShippingCompanyFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return ShippingCompany::query()
            ->when($filters->search !== null, function ($query) use ($filters) {
                $term = '%'.$filters->search.'%';

                // Grouped so the OR set cannot escape and swallow the other filters.
                $query->where(function ($query) use ($term) {
                    $query->where('name', 'ilike', $term)
                        ->orWhere('phone', 'ilike', $term);
                });
            })
            ->when($filters->isActive !== null, fn ($q) => $q->where('is_active', $filters->isActive))
            // In use first, then the usual one, then by name: the picker is opened to choose a
            // carrier, one we stopped dealing with is never the answer, and the company named
            // as the default is the answer nine times in ten.
            ->orderByDesc('is_active')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate($perPage);
    }
}
