<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Actions;

use App\Domain\Carrier\DTOs\NawrisWebhookPayload;
use App\Domain\Carrier\Jobs\ProcessNawrisWebhook;
use App\Domain\Carrier\Models\NawrisWebhookEvent;

/**
 * The request-cycle half of the webhook: log, queue, return.
 *
 * **No business logic runs here, deliberately.** Nawris gets a fast acknowledgement and our
 * processing failures never look like delivery failures to them. Everything that could be slow or
 * could fail — resolving the parcel, moving the order, writing to the ledger — happens in
 * {@see ProcessNawrisWebhook}.
 *
 * **Storing before believing.** The body is written verbatim before anything is interpreted from
 * it, because they do not re-send: whatever is not stored when it arrives is gone permanently.
 *
 * The duplicate check is `firstWhere` on the unique fingerprint rather than an insert-and-catch,
 * since there is no try/catch anywhere in `app/`. The index is still the guarantee; this is the
 * readable path to it.
 */
final class RecordNawrisWebhook
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __invoke(array $body): NawrisWebhookEvent
    {
        $payload = NawrisWebhookPayload::fromArray($body);
        $fingerprint = $payload->fingerprint();

        $existing = NawrisWebhookEvent::query()->where('fingerprint', $fingerprint)->first();

        // A genuine re-send of the same news. Answered 200 like any other: from their side
        // nothing is wrong, and telling them otherwise would invite a retry loop.
        if ($existing !== null) {
            return $existing;
        }

        $event = new NawrisWebhookEvent;

        $event->forceFill([
            'payload' => $body,
            'fingerprint' => $fingerprint,
            // Filled by the job once the parcel is resolved — null here is not a failure, it is
            // simply the fact that nothing has looked yet.
            'nawris_parcel_id' => null,
            'code' => $payload->code,
            'reference' => $payload->reference,
            'status_code' => $payload->statusCode,
            'collected_amount' => $payload->collectedAmount,
            'received_at' => now(),
        ])->save();

        ProcessNawrisWebhook::dispatch($event->getKey());

        return $event;
    }
}
