<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Exceptions\ImageDoesNotBelongToProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use Illuminate\Support\Facades\DB;

/**
 * Makes one image the product's primary, demoting whichever held the title.
 *
 * The demotion has to happen before the promotion: the database enforces at most one primary
 * per product with a partial unique index, so setting the new flag while the old one still
 * stands would be rejected. Both statements run in one transaction, so no reader ever sees a
 * product with no primary image at all.
 */
final class SetPrimaryProductImage
{
    public function __invoke(Product $product, ProductImage $image): ProductImage
    {
        if ((int) $image->product_id !== (int) $product->getKey()) {
            throw ImageDoesNotBelongToProduct::make((int) $image->getKey(), (int) $product->getKey());
        }

        return DB::transaction(function () use ($product, $image): ProductImage {
            $product->images()
                ->where('is_primary', true)
                ->whereKeyNot($image->getKey())
                ->update(['is_primary' => false]);

            $image->update(['is_primary' => true]);

            return $image;
        });
    }
}
