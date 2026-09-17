<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Actions;

use App\Domain\Carrier\DTOs\NawrisWebhookPayload;
use App\Domain\Carrier\Models\NawrisParcel;

/**
 * Works out which parcel a webhook is talking about.
 *
 * **Three tiers, and the fallbacks are not optional.** They catch traffic that would otherwise
 * vanish: re-sent parcels frequently arrive with no `remote_order_id` at all, and the contract
 * records that the correlation id has been observed echoed on a parcel that was not ours. So the
 * reference is the primary key to look on and never proof of identity — the delivery-conflict
 * guard in {@see ApplyNawrisStatus} is what treats the parcel code as a second opinion.
 *
 * A miss is not an error here. It returns null, the caller stores the event with a null parcel,
 * and somebody can find and replay it — which is the whole reason that column is nullable.
 */
final class ResolveNawrisParcel
{
    public function __invoke(NawrisWebhookPayload $payload): ?NawrisParcel
    {
        return $this->byReference($payload)
            ?? $this->byCode($payload)
            ?? $this->byBarCode($payload);
    }

    /** Tier one: our own correlation id, echoed back. */
    private function byReference(NawrisWebhookPayload $payload): ?NawrisParcel
    {
        if ($payload->reference === null) {
            return null;
        }

        return NawrisParcel::query()->where('reference', $payload->reference)->first();
    }

    /** Tier two: their parcel code. */
    private function byCode(NawrisWebhookPayload $payload): ?NawrisParcel
    {
        if ($payload->code === null) {
            return null;
        }

        return NawrisParcel::query()->where('code', $payload->code)->first();
    }

    /**
     * Tier three: the barcode, which is what is physically scanned.
     *
     * The last resort, and the one that catches a re-send announced under a code we have never
     * seen while the label in the courier's hand still carries the barcode we recorded.
     */
    private function byBarCode(NawrisWebhookPayload $payload): ?NawrisParcel
    {
        if ($payload->code === null) {
            return null;
        }

        return NawrisParcel::query()->where('bar_code', $payload->code)->first();
    }
}
