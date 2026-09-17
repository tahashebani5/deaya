<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Exceptions\ImageDoesNotBelongToProduct;
use App\Domain\Catalog\Exceptions\ProductMustKeepOneImage;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes an image, and promotes a replacement if it was the primary one.
 *
 * Refuses to remove the last one: a product always has a photo, and this is the only route that
 * could take the last one away.
 */
final class DeleteProductImage
{
    public function __construct(private readonly SetPrimaryProductImage $setPrimary) {}

    public function __invoke(Product $product, ProductImage $image): void
    {
        if ((int) $image->product_id !== (int) $product->getKey()) {
            throw ImageDoesNotBelongToProduct::make((int) $image->getKey(), (int) $product->getKey());
        }

        // Counted rather than read off a loaded relation: the caller may have loaded `images`
        // before another request uploaded one, and a stale count here refuses a delete that is
        // actually fine.
        if ($product->images()->whereKeyNot($image->getKey())->doesntExist()) {
            throw ProductMustKeepOneImage::make();
        }

        $wasPrimary = $image->is_primary;
        $disk = $image->disk;
        $path = $image->path;

        DB::transaction(function () use ($product, $image, $wasPrimary): void {
            $image->delete();

            // A product that still has photos should never be left without a thumbnail.
            if ($wasPrimary) {
                $replacement = $product->images()->first();

                if ($replacement !== null) {
                    ($this->setPrimary)($product, $replacement);
                }
            }
        });

        // Deliberately after the transaction commits. Removing a file is not transactional, so
        // doing it inside would delete the object even if the transaction later rolled back,
        // leaving a row pointing at nothing. This way the worst case is an orphaned file, which
        // costs storage rather than breaking the catalogue.
        Storage::disk($disk)->delete($path);
    }
}
