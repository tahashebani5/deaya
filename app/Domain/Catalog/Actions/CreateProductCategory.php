<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\DTOs\ProductCategoryData;
use App\Domain\Catalog\Models\ProductCategory;

final class CreateProductCategory
{
    public function __invoke(ProductCategoryData $data): ProductCategory
    {
        $category = ProductCategory::create([
            'name' => $data->name,
            'parent_id' => $data->parentId,
            'description' => $data->description,
            'is_active' => $data->isActive,
            'sort_order' => $data->sortOrder,
            'production_mode' => $data->productionMode,
            'is_investable' => $data->isInvestable,
        ]);

        // A brand-new category has none, but the count must still be present: the resource
        // renders it, and strict mode turns a missing attribute into an exception, not a null.
        return $category->loadCount(['products', 'children']);
    }
}
