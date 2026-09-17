<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Catalog\Models\ProductPriceTier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductPriceTier
 */
class ProductPriceTierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Strings, not numbers. 1.10 has no exact float representation, and a price that
            // arrives as 1.1000000000000001 is not the price the catalogue printed.
            'min_quantity' => (string) $this->min_quantity,
            'unit_price' => (string) $this->unit_price,
        ];
    }
}
