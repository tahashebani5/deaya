<?php

declare(strict_types=1);

namespace App\Domain\Customer\Queries;

use App\Domain\Customer\Models\BusinessField;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The business-field picker's listing, and the management screen's.
 *
 * One query for both: they ask the same question and differ only by `is_active`.
 */
final class BusinessFieldListQuery
{
    /**
     * @return LengthAwarePaginator<int, BusinessField>
     */
    public function __invoke(BusinessFieldFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return BusinessField::query()
            // Counted, not loaded: the screen says how many shops are in each trade, and
            // loading them to count them would be a query per row — and eventually thousands
            // of shops to produce one number.
            ->withCount('shops')
            ->when(
                $filters->search !== null,
                fn ($query) => $query->where('name', 'ilike', '%'.$filters->search.'%'),
            )
            ->when($filters->isActive !== null, fn ($query) => $query->where('is_active', $filters->isActive))
            // The business's own order first, then the name so equal ranks are at least stable
            // — an unordered list renders differently on every request and looks like a bug.
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($perPage);
    }
}
