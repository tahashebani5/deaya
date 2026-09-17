<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\DTOs\ProductCategoryData;
use App\Domain\Catalog\Models\ProductCategory;

/**
 * Replaces a category with what was sent.
 *
 * **Renaming is allowed even when products point at it**, deliberately: this is a *label*, not a
 * snapshot. An order records what it cost on the day; a product records where it sits in the
 * catalogue, and fixing «ستيكرات» spelt wrong should fix it everywhere at once.
 */
final class UpdateProductCategory
{
    public function __invoke(ProductCategory $category, ProductCategoryData $data): ProductCategory
    {
        $category->update([
            'name' => $data->name,
            'parent_id' => $data->parentId,
            'description' => $data->description,
            'is_active' => $data->isActive,
            'sort_order' => $data->sortOrder,
            // **Orders already taken keep the road they were taken under.** Changing this
            // re-routes the *next* order under the heading, never one in flight — the flow is
            // stamped on the order at intake, see `ResolveOrderFlow`.
            'production_mode' => $data->productionMode,
            // **A deal already open is untouched by this.** Its shelves were checked when they
            // were named and its terms froze when it opened — see `SyncDealItems`. Taking the
            // flag off closes the heading to the *next* deal, exactly as the line above closes
            // it to the next order's road.
            'is_investable' => $data->isInvestable,
        ]);

        return $category->loadCount(['products', 'children']);
    }
}
