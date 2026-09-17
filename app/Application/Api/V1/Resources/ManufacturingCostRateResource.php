<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Order\Models\ManufacturingCostRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ManufacturingCostRate
 */
class ManufacturingCostRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Both null on the default rate; at most one of the two is ever set — see the
            // model's docblock. Objects rather than bare ids so a screen can show a name without
            // loading the whole catalogue to translate one.
            'product' => $this->whenLoaded('product', fn () => $this->product === null ? null : [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ]),
            'product_variant' => $this->whenLoaded('productVariant', fn () => $this->productVariant === null ? null : [
                'id' => $this->productVariant->id,
                'label' => $this->productVariant->label,
            ]),
            'cost_type' => $this->cost_type->value,
            'cost_type_label' => $this->cost_type->label(),
            'rate_per_unit' => (string) $this->rate_per_unit,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
