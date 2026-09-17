<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources\Client;

use App\Application\Api\V1\Resources\ProductCategoryResource;
use App\Domain\Catalog\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A catalogue heading, as a filter chip.
 *
 * Four fields where {@see ProductCategoryResource} has fifteen. That one feeds an edit form and
 * a production screen, so it carries `production_mode`, `is_investable` and `skips_production` —
 * whether a heading's work happens in our press or at a vendor's bench, and whose money paid for
 * the stock. None of that describes the product to the person buying it.
 *
 * @mixin ProductCategory
 */
class ClientProductCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'image_url' => $this->imageUrl(),
        ];
    }
}
