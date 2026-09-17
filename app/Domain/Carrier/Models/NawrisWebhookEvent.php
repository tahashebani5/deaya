<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Models;

use Database\Factories\NawrisWebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One inbound webhook, stored before anything is believed about it.
 *
 * **Deliberately neither `Auditable` nor `SoftDeletes`**, and listed in
 * `ModelConventionsTest::NOT_A_BUSINESS_RECORD` so that reads as a decision. It is already an
 * immutable record of an external event: a second immutable record saying the first one was
 * written has no reader, and a log that can be deleted is not a log. It is also the only table in
 * this context that grows with traffic rather than with business.
 *
 * `payload` stays authoritative. Every extracted column exists so an unmatched event can be
 * *found*, never so it can replace what was actually sent.
 */
#[UseFactory(NawrisWebhookEventFactory::class)]
#[Fillable([])]
class NawrisWebhookEvent extends Model
{
    /** @use HasFactory<NawrisWebhookEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status_code' => 'integer',
            'collected_amount' => 'decimal:2',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Null for an event that matched nothing — which is the whole reason the column is nullable.
     *
     * @return BelongsTo<NawrisParcel, $this>
     */
    public function parcel(): BelongsTo
    {
        return $this->belongsTo(NawrisParcel::class, 'nawris_parcel_id');
    }

    /** Received, and the work still not done. The queue somebody has to watch. */
    public function isPending(): bool
    {
        return $this->processed_at === null;
    }

    /** Nothing here matched a parcel we know about. */
    public function isUnmatched(): bool
    {
        return $this->nawris_parcel_id === null;
    }
}
