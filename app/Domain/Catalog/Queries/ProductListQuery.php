<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Queries;

use App\Domain\Catalog\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The catalogue listing, in the order the printed catalogue uses.
 */
final class ProductListQuery
{
    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function __invoke(ProductFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return Product::query()
            // Two levels deep: the resource renders every variant's price list, which would
            // otherwise be a query per variant per row.
            ->with(['variants.priceTiers', 'variants.stockItem', 'images', 'productCategory.parent', 'stockItemGroup'])
            ->when($filters->search !== null, function ($query) use ($filters) {
                $term = '%'.$filters->search.'%';

                // Grouped so the OR set cannot escape and swallow the other filters.
                $query->where(function ($query) use ($term) {
                    $query->where('name', 'ilike', $term)
                        ->orWhere('slug', 'ilike', $term);
                });
            })
            // **A heading matches what is under its subheadings too.** A parent holds no
            // products of its own, so matching only the row itself would answer «لا منتجات» for
            // «أكياس» while «أكياس ورقية» underneath it is full.
            ->when(
                $filters->productCategoryId !== null,
                fn ($q) => $q->whereIn(
                    'product_category_id',
                    ProductCategoryListQuery::idsUnder($filters->productCategoryId),
                ),
            )
            ->when($filters->pricingUnit !== null, fn ($q) => $q->where('pricing_unit', $filters->pricingUnit))
            ->when($filters->pricingMode !== null, fn ($q) => $q->where('pricing_mode', $filters->pricingMode))
            ->when($filters->isActive !== null, fn ($q) => $q->where('is_active', $filters->isActive))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage);
    }
}
