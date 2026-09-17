<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Carrier\Models\NawrisParcel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A parcel as Nawris knows it.
 *
 * @mixin NawrisParcel
 */
class NawrisParcelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'reference' => $this->reference,
            'bar_code' => $this->bar_code,

            'government' => $this->government,
            'area' => $this->area,

            // Strings, never floats: money that a client re-serialises must survive the trip.
            'amount_to_collect' => (string) $this->amount_to_collect,
            'delivery_price_deducted' => (string) $this->delivery_price_deducted,
            'collected_amount' => $this->collected_amount !== null ? (string) $this->collected_amount : null,

            // Their integer and their prose, both — the mapping is written against the first and
            // support reads the second.
            'remote_status_code' => $this->remote_status_code,
            'remote_status_text' => $this->remote_status_text,

            'is_open' => $this->isOpen(),
            'has_open_conflict' => $this->hasOpenConflict(),
            'conflict_raised_at' => $this->conflict_raised_at?->toIso8601String(),
            'conflict_resolved_at' => $this->conflict_resolved_at?->toIso8601String(),

            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}
