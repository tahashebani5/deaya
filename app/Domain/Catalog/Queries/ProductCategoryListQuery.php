<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Queries;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The category picker's listing, and the management screen's.
 *
 * One query for both: they ask the same question and differ only by which rows they want —
 * `is_active` for a picker, `leafOnly` for the one on the product form.
 */
final class ProductCategoryListQuery
{
    /**
     * @return LengthAwarePaginator<int, ProductCategory>
     */
    public function __invoke(ProductCategoryFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return ProductCategory::query()
            // Counted, not loaded: the screen says how many products are in each category, and
            // loading them to count them would be a query per row.
            ->select('product_categories.*')
            ->withCount(['products', 'children'])
            // Products filed under this heading's *children*. Zero for a leaf by construction,
            // and the number the management screen actually shows for a parent — see
            // ProductCategoryResource.
            //
            // A correlated subquery rather than `withCount`, and that is not a preference:
            // `withCount('products')` constrains the relation to this row first, so a closure
            // narrowing it to the children's ids can only ever count zero.
            ->addSelect([
                'descendant_products_count' => Product::query()
                    ->selectRaw('count(*)')
                    ->whereIn(
                        'product_category_id',
                        // Aliased, and that is the whole of it: both sides of this are
                        // `product_categories`, so an unaliased `product_categories.id` binds to
                        // the *inner* table and the subquery quietly compares a row to itself.
                        ProductCategory::query()
                            ->from('product_categories as child')
                            ->select('child.id')
                            ->whereColumn('child.parent_id', 'product_categories.id'),
                    ),
            ])
            ->with('parent:id,name')
            ->when(
                $filters->search !== null,
                fn (Builder $query) => $query->where('name', 'ilike', '%'.$filters->search.'%'),
            )
            ->when(
                $filters->isActive !== null,
                fn (Builder $query) => $query->where('is_active', $filters->isActive),
            )
            // What a picker on the product form asks for: the headings a product may actually be
            // filed under. A parent is a heading, not a slot.
            ->when(
                $filters->leafOnly,
                fn (Builder $query) => $query->whereDoesntHave('children'),
            )
            // And, for that same picker, nothing hidden behind a stopped parent: the root was
            // stopped precisely to take that part of the catalogue out of circulation, and
            // honouring only the child's own flag would leave half the decision applied.
            ->when(
                $filters->isActive === true,
                fn (Builder $query) => $query->where(
                    fn (Builder $query) => $query
                        ->whereNull('parent_id')
                        ->orWhereHas('parent', fn (Builder $parent) => $parent->where('is_active', true)),
                ),
            )
            // The catalogue's own order first, then the name so equal ranks are at least stable
            // — an unordered list renders differently on every request and looks like a bug.
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * The ids a filter on this category should match: itself, and its children.
     *
     * **Filtering by «أكياس» must return what is under «أكياس ورقية» too.** A parent holds no
     * products of its own, so a filter that matched only the row itself would answer nothing at
     * all — which reads as «لا منتجات» rather than as «هذا عنوان».
     *
     * @return list<int>
     */
    public static function idsUnder(int $categoryId): array
    {
        return [
            $categoryId,
            ...ProductCategory::query()->where('parent_id', $categoryId)->pluck('id')->all(),
        ];
    }
}
