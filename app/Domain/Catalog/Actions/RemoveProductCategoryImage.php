<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\ProductCategory;
use Illuminate\Support\Facades\Storage;

/**
 * Takes the picture off a heading, and off the disk.
 *
 * A real delete, for the reason {@see SetProductCategoryImage} gives: nothing anywhere points at
 * a category's picture the way an order points at a design, so keeping the bytes would be
 * storage nobody will ever read.
 *
 * Idempotent — a heading with no picture is already in the state this asks for, and a second
 * tap after a dropped connection must not be an error.
 */
final class RemoveProductCategoryImage
{
    public function __invoke(ProductCategory $category): ProductCategory
    {
        if (! $category->hasImage()) {
            return $category;
        }

        $disk = (string) $category->image_disk;
        $path = (string) $category->image_path;

        // The row first: a heading whose columns are cleared shows no picture, which is what was
        // asked for. A failed delete afterwards leaves a file nobody references — storage spent,
        // and the cheaper of the two failures.
        // `forceFill` for the reason SetProductCategoryImage gives: these columns are the
        // domain's to write and are deliberately absent from the fillable list.
        $category->forceFill([
            'image_disk' => null,
            'image_path' => null,
            'image_width_px' => null,
            'image_height_px' => null,
        ])->save();

        Storage::disk($disk)->delete($path);

        return $category->refresh();
    }
}
