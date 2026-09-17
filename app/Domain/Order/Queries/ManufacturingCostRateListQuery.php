<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries;

use App\Domain\Order\Models\ManufacturingCostRate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ManufacturingCostRateListQuery
{
    /**
     * @return LengthAwarePaginator<int, ManufacturingCostRate>
     */
    public function __invoke(ManufacturingCostRateFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return ManufacturingCostRate::query()
            ->with(['product', 'productVariant'])
            ->when($filters->productId !== null, fn ($query) => $query->where('product_id', $filters->productId))
            ->when(
                $filters->productVariantId !== null,
                fn ($query) => $query->where('product_variant_id', $filters->productVariantId),
            )
            ->when($filters->costType !== null, fn ($query) => $query->where('cost_type', $filters->costType))
            ->when($filters->isActive !== null, fn ($query) => $query->where('is_active', $filters->isActive))
            // Most specific first — a size's own rate, then a product's, then the default — the
            // same order ApplyManufacturingRates looks them up in, so the list reads as a
            // priority list rather than an arbitrary one.
            ->orderByRaw('CASE WHEN product_variant_id IS NOT NULL THEN 0 WHEN product_id IS NOT NULL THEN 1 ELSE 2 END')
            ->orderBy('cost_type')
            ->paginate($perPage);
    }
}
