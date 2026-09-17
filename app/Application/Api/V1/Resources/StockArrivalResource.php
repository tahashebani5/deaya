<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Vendor\Models\StockArrival;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockArrival
 */
class StockArrivalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'vendor_id' => $this->vendor_id,
            'vendor' => $this->whenLoaded('vendor', fn (): array => [
                'id' => $this->vendor->id,
                'name' => $this->vendor->name,
            ]),

            // Which purchase order this shipment was fulfilling, if any — most arrivals are
            // unplanned and this is null. A plain scalar, not a nested object: the order itself
            // is read through GET /purchase-orders/{purchase_order}, the same way order_id
            // works everywhere else in this API.
            'purchase_order_id' => $this->purchase_order_id,

            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded(
                'warehouse',
                fn (): ?array => $this->warehouse === null ? null : [
                    'id' => $this->warehouse->id,
                    'name' => $this->warehouse->name,
                ],
            ),

            'invoice_number' => $this->invoice_number,
            'notes' => $this->notes,

            'received_by' => $this->received_by,
            'received_by_user' => $this->whenLoaded('receivedByUser', fn (): array => [
                'id' => $this->receivedByUser->id,
                'name' => $this->receivedByUser->name,
            ]),

            // Set only on a receipt somebody entered in error and took back — null on every one
            // that stands. The document is kept and annotated rather than deleted, so a screen
            // showing the shipment can say «مُلغى: سُجّلت الكمية خطأً» instead of quietly not
            // showing it at all.
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversal_reason' => $this->reversal_reason,
            'reversed_by' => $this->reversed_by,
            'reversed_by_user' => $this->whenLoaded(
                'reversedByUser',
                fn (): ?array => $this->reversedByUser === null ? null : [
                    'id' => $this->reversedByUser->id,
                    'name' => $this->reversedByUser->name,
                ],
            ),

            'items' => StockArrivalItemResource::collection($this->whenLoaded('items')),

            // No `updated_at`: the only thing that ever changes on an arrival is the reversal
            // stamp above, which says when it happened itself — publishing a bare timestamp
            // would only invite a client to believe the document could be edited.
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
