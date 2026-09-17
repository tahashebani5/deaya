<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Order\Models\OrderDesign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One version of an order's artwork.
 *
 * The file's details come from the customer's library through the relation rather than being
 * repeated on this table — the design is pointed at, not copied.
 *
 * @mixin OrderDesign
 */
class OrderDesignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_reviewed' => $this->status->isReviewed(),

            'rejection_reason' => $this->rejection_reason,
            'notes' => $this->notes,

            'customer_design_id' => $this->customer_design_id,
            'design' => new CustomerDesignResource($this->whenLoaded('customerDesign')),

            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
