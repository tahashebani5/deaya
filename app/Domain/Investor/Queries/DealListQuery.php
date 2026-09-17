<?php

declare(strict_types=1);

namespace App\Domain\Investor\Queries;

use App\Domain\Investor\Models\InvestorDeal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** The deals screen: newest first, filterable by status and by who is in one. */
final class DealListQuery
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, InvestorDeal>
     */
    public function __invoke(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';

        return InvestorDeal::query()
            ->with(['product', 'shares.investor'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->orWhere('code', 'ilike', '%'.$search.'%')))
            ->when(
                isset($filters['status']) && $filters['status'] !== '',
                fn ($q) => $q->where('status', $filters['status']),
            )
            ->when(
                isset($filters['investor_id']) && $filters['investor_id'] !== '',
                fn ($q) => $q->whereHas('shares', fn ($s) => $s->where('investor_id', (int) $filters['investor_id'])),
            )
            ->orderByDesc('opened_on')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
