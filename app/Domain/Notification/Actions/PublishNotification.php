<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Audience\ResolveRecipients;
use App\Domain\Notification\Channels\DatabaseChannel;
use App\Domain\Notification\Channels\PushChannel;
use App\Domain\Notification\Contracts\NotificationDefinition;
use App\Domain\Notification\DTOs\PendingNotification;
use App\Domain\Notification\Models\Notification;
use Illuminate\Support\Carbon;

/**
 * Writes a notification and fans it out. The single way anything enters the centre.
 *
 * ### It must never be able to roll back the thing it is about
 *
 * **The listeners that call this are queued and run after commit — unlike every other listener
 * in this application, and that inversion is deliberate.**
 * `PostEarningsWhenOrderIsFinalised` is synchronous *inside* the transaction on purpose, because
 * it moves money and «either the status moves and the money is booked or neither happens». A
 * notification is the opposite bargain: a failed push must never undo an order status change,
 * and a notification about a transaction that later rolled back must never have been sent at
 * all. Copy the neighbour's shape here and the first failed FCM call takes a delivered order
 * down with it.
 *
 * ### Nobody is an ordinary outcome
 *
 * A permission held by no one, or held only by the person who caused the event, resolves to an
 * empty audience. The `notifications` row is still written — it is a record that the thing
 * happened — and no recipients are. Callers do not check; that is the point of them not knowing
 * who the audience is.
 */
final readonly class PublishNotification
{
    /**
     * How long a `dedupe_key` suppresses a repeat.
     *
     * Storm control, and the reason a bell stays worth looking at. Six hours is long enough to
     * cover a shift — an order bouncing in and out of «نواقص» all morning is one notification,
     * not nine — and short enough that the same thing genuinely recurring tomorrow is heard.
     *
     * A constant rather than config: it is a product decision about attention, not a knob an
     * operator should turn on a live box.
     */
    private const DEDUPE_WINDOW_HOURS = 6;

    public function __construct(
        private ResolveRecipients $recipients,
        private DatabaseChannel $database,
        private PushChannel $push,
    ) {}

    /**
     * @return Notification|null null when an identical notification is already standing inside
     *                           the storm window — nothing was written and nobody was told
     */
    public function handle(PendingNotification $pending): ?Notification
    {
        if ($this->isDuplicate($pending)) {
            return null;
        }

        /** @var NotificationDefinition $definition */
        $definition = app($pending->type->definition());

        $notification = new Notification;
        $notification->type = $pending->type;
        $notification->subject_type = $pending->subjectType;
        $notification->subject_id = $pending->subjectId;
        $notification->payload = $pending->payload;
        $notification->dedupe_key = $pending->dedupeKey;
        $notification->causer_id = $pending->causerId;
        $notification->save();

        $userIds = $this->recipients->handle(
            $definition->audience($pending->payload),
            $pending->causerId,
            $definition->notifiesCauser(),
        );

        // The mailbox first and always; the push is a courtesy on top of a record that already
        // exists. Ordered this way so a push that fails still leaves something to come back to.
        $this->database->deliver($notification, $userIds);
        $this->push->deliver($notification, $userIds);

        return $notification;
    }

    /**
     * Whether the same thing was already said recently enough that saying it again is noise.
     *
     * Keyed on `dedupe_key` alone rather than on the type as well, because the key is built to
     * carry its own type — `order.shortage:145` — and two kinds of notification sharing a key
     * would be a bug in the definition rather than something to paper over here.
     */
    private function isDuplicate(PendingNotification $pending): bool
    {
        if ($pending->dedupeKey === null) {
            return false;
        }

        return Notification::query()
            ->where('dedupe_key', $pending->dedupeKey)
            ->where('created_at', '>=', Carbon::now()->subHours(self::DEDUPE_WINDOW_HOURS))
            ->exists();
    }
}
