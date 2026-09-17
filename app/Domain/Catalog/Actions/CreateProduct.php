<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\DTOs\ProductData;
use App\Domain\Catalog\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Adds a product to the catalogue, photo included.
 *
 * **The photo is a separate argument rather than a field on {@see ProductData}.** The DTO is
 * plain data that crosses boundaries and gets built from an array; an `UploadedFile` is a
 * framework object holding a handle to a file on disk, and putting one inside would drag HTTP
 * into a structure that has none in it.
 */
final class CreateProduct
{
    public function __construct(
        private readonly SyncProductVariants $syncVariants,
        private readonly UploadProductImage $uploadImage,
    ) {}

    public function __invoke(ProductData $data, UploadedFile $image, ?string $altText = null): Product
    {
        return DB::transaction(function () use ($data, $image, $altText): Product {
            $product = Product::create([
                'slug' => $data->slug,
                'name' => $data->name,
                'description' => $data->description,
                'features' => $data->features,
                'product_category_id' => $data->productCategoryId,
                // Written before the variants are synced, deliberately: SyncProductVariants reads
                // it off the product to resolve each size's shelf, so a product created with a
                // material has its sizes filed under it in the same call.
                'stock_item_group_id' => $data->stockItemGroupId,
                'pricing_unit' => $data->pricingUnit,
                'pricing_mode' => $data->pricingMode,
                'min_order_quantity' => $data->minOrderQuantity,
                'is_active' => $data->isActive ?? true,
                'sort_order' => $data->sortOrder,
            ]);

            ($this->syncVariants)($product, $data->variants ?? []);

            // Inside the transaction, so a product is never committed without its photo. Writing
            // the *file* is not transactional, so a rollback here leaves an object on disk with
            // no row pointing at it — storage spent, which is the cheaper of the two failures and
            // the same trade DeleteProductImage makes in the other direction.
            ($this->uploadImage)($product, $image, $altText);

            return $product->load(['variants.priceTiers', 'variants.stockItem', 'images', 'productCategory.parent', 'stockItemGroup']);
        });
    }
}
