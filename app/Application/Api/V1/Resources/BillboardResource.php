<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Application\Api\V1\Resources\Client\ClientBillboardResource;
use App\Domain\Marketing\Models\Billboard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A banner as the person managing it sees one — schedule, order, switch and all.
 *
 * The customer's version is {@see ClientBillboardResource},
 * and it carries none of that: the app is sent what is showing now, so a start date it cannot
 * act on is noise at best.
 *
 * @mixin Billboard
 */
class BillboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->displayName(),
            'image_url' => $this->imageUrl(),

            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn (): ?array => $this->product === null ? null : [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ]),
            'external_url' => $this->external_url,

            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),

            // Whether this banner is in front of customers *right now* — the three conditions
            // resolved into the one answer the management list actually draws. A screen that
            // recomputed it from the three fields above would be a second copy of the rule in
            // `Billboard::scopeShowingNow()`, and the copy that drifts is always the one on the
            // client.
            'is_showing_now' => $this->is_active
                && ($this->starts_at === null || $this->starts_at->isPast())
                && ($this->ends_at === null || $this->ends_at->isFuture()),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
