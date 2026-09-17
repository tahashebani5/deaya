<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Inventory\Models\StockItemGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockItemGroup
 */
class StockItemGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Server-assigned, never settable — «G7».
            'code' => $this->code,

            'name' => $this->name,

            // What a size created under this material starts out counted in. Not the authority
            // for an existing shelf: that is `stock_items.unit`, and it moves only through
            // PATCH /stock-items/{stock_item}/unit.
            'default_unit' => $this->default_unit->value,
            'default_unit_label' => $this->default_unit->label(),

            'description' => $this->description,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,

            // The two numbers the management screen shows: how many sizes this material comes in,
            // and how many products are made of it. Counted by the query, never by loading rows.
            'items_count' => $this->whenCounted('items'),
            'products_count' => $this->whenCounted('products'),

            // The sizes themselves, smallest first — only when a caller asked for them.
            'items' => StockItemResource::collection($this->whenLoaded('items')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
