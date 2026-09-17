<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Order\Models\ProductionCostEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductionCostEntry
 */
class ProductionCostEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_item_id' => $this->order_item_id,
            'cost_type' => $this->cost_type->value,
            'cost_type_label' => $this->cost_type->label(),
            'quantity' => $this->quantity === null ? null : (string) $this->quantity,
            'rate' => $this->rate === null ? null : (string) $this->rate,
            'amount' => (string) $this->amount,
            'notes' => $this->notes,
            'incurred_at' => $this->incurred_at?->toIso8601String(),
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder === null ? null : [
                'id' => $this->recorder->id,
                'name' => $this->recorder->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
