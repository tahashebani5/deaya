<?php

declare(strict_types=1);

namespace App\Domain\Vendor\Queries;

use App\Domain\Vendor\Models\Vendor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The vendor picker's listing: search, activity filter, newest first.
 */
final class VendorListQuery
{
    /**
     * @return LengthAwarePaginator<int, Vendor>
     */
    public function __invoke(VendorFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return Vendor::query()
            ->when($filters->search !== null, function ($query) use ($filters) {
                $term = '%'.$filters->search.'%';

                // Grouped so the OR set cannot escape and swallow the is_active filter beside it.
                $query->where(function ($query) use ($term) {
                    $query->where('name', 'ilike', $term)
                        ->orWhere('contact_person', 'ilike', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when($filters->isActive !== null, fn ($query) => $query->where('is_active', $filters->isActive))
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
