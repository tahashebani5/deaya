<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Jobs;

use App\Domain\Carrier\Actions\ApplyNawrisStatus;
use App\Domain\Carrier\Actions\ResolveNawrisParcel;
use App\Domain\Carrier\DTOs\NawrisWebhookPayload;
use App\Domain\Carrier\Models\NawrisWebhookEvent;
use App\Domain\Carrier\Support\CarrierAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The work half of the webhook, off the request cycle.
 *
 * **The first queued job in this codebase, which makes it a deployment concern as much as a code
 * one.** `QUEUE_CONNECTION=database` is configured but nothing has ever needed a worker, so if
 * `queue:work` is not running in production every webhook is accepted, stored, answered `200` and
 * never processed — orders silently stop moving while Nawris sees perfect delivery. The
 * unprocessed-events query exists to make that visible; the worker belongs on the deployment
 * checklist.
 *
 * Takes the event's id rather than the model, so a job sitting in the queue cannot carry a stale
 * copy of a row that has since been looked at.
 */
class ProcessNawrisWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $eventId) {}

    public function handle(
        ResolveNawrisParcel $resolve,
        ApplyNawrisStatus $apply,
        CarrierAccount $account,
    ): void {
        $event = NawrisWebhookEvent::query()->find($this->eventId);

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $payload = NawrisWebhookPayload::fromArray((array) $event->payload);

        $parcel = $resolve($payload);

        if ($parcel === null) {
            // **Stored, not dropped.** An unmatched event keeps its row so it can be inspected
            // and replayed; the system this was compiled from logged these and lost them.
            $event->forceFill([
                'error' => 'لم يُعثر على طرد مطابق',
                'processed_at' => now(),
            ])->save();

            return;
        }

        $event->forceFill(['nawris_parcel_id' => $parcel->getKey()])->save();

        $apply($event, $parcel->load('orders'), $payload, $account->user());

        $event->forceFill(['processed_at' => now()])->save();
    }
}
