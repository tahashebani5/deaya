<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\DTOs\ProductData;
use App\Domain\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;

final class UpdateProduct
{
    public function __construct(private readonly SyncProductVariants $syncVariants) {}

    public function __invoke(Product $product, ProductData $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            $attributes = [
                'name' => $data->name,
                'description' => $data->description,
                'features' => $data->features,
                'product_category_id' => $data->productCategoryId,
                'pricing_unit' => $data->pricingUnit,
                'pricing_mode' => $data->pricingMode,
                'min_order_quantity' => $data->minOrderQuantity,
                'sort_order' => $data->sortOrder,
            ];

            // Same rule as `is_active` and `slug`: absent means "leave it". Clearing a product's
            // material would silently detach every one of its sizes from a shelf on this very
            // save — see ProductData::$stockItemGroupId — so an update that does not mention it
            // keeps what was there.
            if ($data->stockItemGroupId !== null) {
                $attributes['stock_item_group_id'] = $data->stockItemGroupId;
            }

            // Same rule as `is_active`: absent means "leave it". A slug is what links point at,
            // and an update that simply did not mention it must not blank the column — it is
            // NOT NULL, so that would be a crash, and if it were nullable it would be worse: a
            // silently broken link.
            if ($data->slug !== null) {
                $attributes['slug'] = $data->slug;
            }

            // Only touched when the caller actually sent it, so an update that omits the field
            // cannot reactivate a product that was deliberately taken off the catalogue.
            if ($data->isActive !== null) {
                $attributes['is_active'] = $data->isActive;
            }

            $product->update($attributes);

            // null means the caller did not mention variants, so the price list is left alone.
            // An empty array is an explicit instruction to clear it.
            if ($data->variants !== null) {
                ($this->syncVariants)($product, $data->variants);
            }

            return $product->load(['variants.priceTiers', 'variants.stockItem', 'images', 'productCategory.parent', 'stockItemGroup']);
        });
    }
}
