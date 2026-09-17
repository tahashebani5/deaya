<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Customer\Models\BusinessField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BusinessField
 */
class BusinessFieldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,

            // How many shops are recorded in this trade. It is what makes the management screen
            // worth opening — and it is the same number that decides whether a delete will be
            // refused, so the screen can say so before the button is pressed.
            'shops_count' => $this->whenCounted('shops'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
