<?php

declare(strict_types=1);

namespace App\Domain\Notification\Contracts;

use App\Domain\Notification\Models\Notification;

/**
 * A way of getting a notification to people.
 *
 * Two exist: the database (the in-app centre, always on and never optional) and FCM push. A
 * third — SMS to a customer, when the business decides to pay for a gateway — is this interface
 * again and nothing else, which is the point of it being an interface for only two
 * implementations today.
 *
 * **A channel must not throw for an ordinary failure.** One dead device cannot be allowed to
 * stop the other recipients being told, so a channel either handles its own trouble or defers it
 * to a queued job that can retry alone. That is a departure from RULES.md §5's "throw, never
 * catch" only in emphasis: nothing here catches anything, the failure simply happens inside a
 * job whose retry is the recovery.
 */
interface NotificationChannel
{
    /**
     * @param  list<int>  $userIds  the already-resolved audience
     */
    public function deliver(Notification $notification, array $userIds): void;
}
