<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Vendor\Models\StockArrivalItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockArrivalItem
 */
class StockArrivalItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Always positive — see StockMovementResource for the same shape.
            'quantity' => (string) $this->quantity,

            // Null for a plain arrival — only set when this line fulfilled a purchase order.
            'unit_cost' => $this->unit_cost !== null ? (string) $this->unit_cost : null,
            'total_cost' => $this->total_cost !== null ? (string) $this->total_cost : null,

            'stock_item_id' => $this->stock_item_id,
            'stock_item' => $this->whenLoaded('stockItem', fn (): array => [
                'id' => $this->stockItem->id,
                'code' => $this->stockItem->code,
                'name' => $this->stockItem->name,
                'width_cm' => $this->stockItem->width_cm,
                'height_cm' => $this->stockItem->height_cm,
                'display_name' => $this->stockItem->displayName(),
            ]),

            // The ledger row this line produced.
            'stock_movement_id' => $this->stock_movement_id,
        ];
    }
}
