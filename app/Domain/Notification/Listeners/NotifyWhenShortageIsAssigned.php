<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Notification\DTOs\PendingNotification;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\NotificationService;
use App\Domain\Shortage\Events\ShortageAssigned;
use App\Domain\Shortage\Models\Shortage;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tells one person that a shortage is now theirs.
 *
 * Lives in Notification rather than in Shortage, like every other listener here: this context
 * reads the ones it reacts to, and none of them may read it back.
 *
 * **No dedupe key.** `NotifyWhenOrderEntersShortage` carries one because an order bounces in and
 * out of «نواقص» while somebody works on it and the news is the same each time. Reassignment is
 * the opposite: each one is addressed to a different person, and suppressing the second would
 * mean the second person was never told.
 *
 * Queued and after commit, for the reason its neighbour spells out — a notification about a
 * transaction that later rolled back cannot be taken back.
 */
final class NotifyWhenShortageIsAssigned implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(ShortageAssigned $event): void
    {
        $shortage = Shortage::query()->find($event->shortageId);

        // It can have gone between the commit and this job running — an order deleted seconds
        // later takes its unsupplied shortages with it. Nothing to say about work that no longer
        // exists.
        if ($shortage === null) {
            return;
        }

        $this->notifications->publish(PendingNotification::about(
            type: NotificationType::ShortageAssigned,
            subject: $shortage,
            // Frozen now, so the sentence still reads correctly after the shortage is supplied
            // or closed — and so the list needs no joins to render.
            payload: [
                'shortage_id' => (int) $shortage->getKey(),
                'shortage_code' => (string) $shortage->code,
                'assignee_id' => $event->assigneeId,
                'name' => (string) $shortage->name,
                'remaining' => $shortage->remainingQuantity(),
                // The word, not the enum value: the app prints what it is handed, which is what
                // lets a unit be relabelled without a client release.
                'unit_label' => $shortage->unit instanceof PricingUnit
                    ? $shortage->unit->label()
                    : '',
            ],
            causerId: $event->actorId,
        ));
    }
}
