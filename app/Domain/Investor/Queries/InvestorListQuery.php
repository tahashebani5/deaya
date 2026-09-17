<?php

declare(strict_types=1);

namespace App\Domain\Investor\Queries;

use App\Domain\Investor\Models\Investor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** The investors screen: active first, then by name, with a search over name, code and phone. */
final class InvestorListQuery
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Investor>
     */
    public function __invoke(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        $isActive = $filters['is_active'] ?? null;

        return Investor::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', '%'.$search.'%')
                ->orWhere('code', 'ilike', '%'.$search.'%')
                ->orWhere('phone', 'ilike', '%'.$search.'%')))
            ->when($isActive !== null && $isActive !== '', fn ($q) => $q->where('is_active', (bool) $isActive))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate($perPage);
    }
}
