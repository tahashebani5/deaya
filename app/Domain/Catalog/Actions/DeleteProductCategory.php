<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Exceptions\ProductCategoryHasChildren;
use App\Domain\Catalog\Exceptions\ProductCategoryInUse;
use App\Domain\Catalog\Models\ProductCategory;

/**
 * Deletes a category nothing is using.
 *
 * The guard is here rather than in the controller so it holds for every caller — a console
 * command, an import, a future bulk tidy-up — and not only for the HTTP route where somebody
 * remembered to write it.
 *
 * Soft, like every delete in this codebase: the row and its history survive, and a category
 * removed by mistake is restorable straight from the database.
 */
final class DeleteProductCategory
{
    public function __invoke(ProductCategory $category): void
    {
        // **Children hold a heading back before products do.** Deleting is soft, so a parent
        // removed from under its children would leave them pointing at a row the API no longer
        // returns — headings nobody can name, restore or re-file.
        $children = $category->children()->count();

        if ($children > 0) {
            throw ProductCategoryHasChildren::make($category->name, $children);
        }

        // Trashed products count too — see ProductCategory::isInUse().
        $inUse = $category->products()->withTrashed()->count();

        if ($inUse > 0) {
            throw ProductCategoryInUse::make($category->name, $inUse);
        }

        $category->delete();
    }
}
