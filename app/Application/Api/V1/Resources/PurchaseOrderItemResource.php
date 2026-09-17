<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\PurchaseOrder\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseOrderItem
 */
class PurchaseOrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'stock_item_id' => $this->stock_item_id,
            // What was bought: a shelf, at a size, with its own price. «كيس شحن 25*35» and
            // «كيس شحن 35*40» are two items and therefore two lines here — the size lives on the
            // item, so per-size pricing survives the move off product variants intact.
            'stock_item' => $this->whenLoaded('stockItem', fn (): array => [
                'id' => $this->stockItem->id,
                'code' => $this->stockItem->code,
                'name' => $this->stockItem->name,
                'width_cm' => $this->stockItem->width_cm,
                'height_cm' => $this->stockItem->height_cm,
                'display_name' => $this->stockItem->displayName(),
            ]),

            // Always positive — see StockArrivalItemResource for the same shape.
            'quantity_ordered' => (string) $this->quantity_ordered,
            'quantity_received' => (string) $this->quantity_received,

            // Derived rather than trusted from either column alone, so a client never has to
            // subtract two decimal strings itself.
            //
            // **Floored at zero, and the surplus published beside it.** An overshipped line is
            // owed nothing — «متبقٍ ‎-٢٠٠» reads as a debt that runs the wrong way, and every
            // screen asking «is anything still coming» would have to know that a negative means
            // no. What arrived over the order is a fact of its own, so it is sent as one.
            'quantity_remaining' => $this->remaining(),
            'quantity_over_received' => $this->overReceived(),

            // Null only on a line written before cost tracking existed.
            'base_total_cost' => $this->base_total_cost !== null ? (string) $this->base_total_cost : null,
            'base_unit_cost' => $this->base_unit_cost !== null ? (string) $this->base_unit_cost : null,
            'allocated_additional_cost' => $this->allocated_additional_cost !== null ? (string) $this->allocated_additional_cost : null,
            // The landed cost — includes this line's share of the order's additional costs.
            'final_unit_cost' => $this->final_unit_cost !== null ? (string) $this->final_unit_cost : null,
            'final_total_cost' => $this->final_total_cost !== null ? (string) $this->final_total_cost : null,

            'unit' => $this->unit?->value,
            'unit_label' => $this->unit?->label(),
        ];
    }

    /** What is still owing on this line — never less than nothing. */
    private function remaining(): string
    {
        $remaining = bcsub((string) $this->quantity_ordered, (string) $this->quantity_received, 3);

        return bccomp($remaining, '0', 3) > 0 ? $remaining : '0.000';
    }

    /** What arrived beyond what was ordered — `'0.000'` on the ordinary line. */
    private function overReceived(): string
    {
        $over = bcsub((string) $this->quantity_received, (string) $this->quantity_ordered, 3);

        return bccomp($over, '0', 3) > 0 ? $over : '0.000';
    }
}
