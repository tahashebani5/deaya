<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Carrier\Models\NawrisWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One inbound webhook, as the operations screen reads it.
 *
 * @mixin NawrisWebhookEvent
 */
class NawrisWebhookEventResource extends JsonResource
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
            'status_code' => $this->status_code,
            'collected_amount' => $this->collected_amount !== null ? (string) $this->collected_amount : null,

            // The two questions this screen exists to answer.
            'is_unmatched' => $this->isUnmatched(),
            'is_pending' => $this->isPending(),

            'error' => $this->error,
            'nawris_parcel_id' => $this->nawris_parcel_id,

            // Verbatim, because the whole point of storing it is that they never re-send and
            // whatever we did not keep is gone.
            'payload' => $this->payload,

            'received_at' => $this->received_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
        ];
    }
}
