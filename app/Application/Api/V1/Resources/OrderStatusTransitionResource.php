<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Order\Models\OrderStatusTransition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One move on an order's timeline.
 *
 * @mixin OrderStatusTransition
 */
class OrderStatusTransitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Null exactly once per order: the row recording that it was taken.
            'from_status' => $this->from_status?->value,
            'from_status_label' => $this->from_status?->label(),

            'to_status' => $this->to_status->value,
            'to_status_label' => $this->to_status->label(),

            'reason' => $this->reason,

            'user' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
