<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Warehouse
 */
class WarehouseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,

            // Both the stored value and its Arabic label, so a client renders a picker without
            // shipping its own copy of the translation — the same shape every enum-backed field
            // in this API uses.
            'type' => $this->type->value,
            'type_label' => $this->type->label(),

            'location' => $this->location,

            // How many distinct sizes have a balance line here — counted, never loaded. A
            // warehouse can hold hundreds, and a list row needs the number, not the rows.
            'stocks_count' => $this->whenCounted('stocks'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
