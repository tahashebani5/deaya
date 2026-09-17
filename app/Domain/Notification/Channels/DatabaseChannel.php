<?php

declare(strict_types=1);

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Contracts\NotificationChannel;
use App\Domain\Notification\Models\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The in-app notification centre — the record every other channel is a courtesy on top of.
 *
 * **Always on, and deliberately not switchable.** Push can be declined, by the user or by a
 * phone that has lost its token; the mailbox cannot, or a person who was away has no way to
 * discover what they missed.
 *
 * One `insert` for the whole audience rather than a row at a time: a staff-wide announcement is
 * one statement, not thirty, and none of these rows fires a model event anybody listens for.
 */
final readonly class DatabaseChannel implements NotificationChannel
{
    /**
     * @param  list<int>  $userIds
     */
    public function deliver(Notification $notification, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $now = Carbon::now();

        DB::table('notification_recipients')->insert(array_map(fn (int $userId) => [
            'notification_id' => $notification->getKey(),
            'user_id' => $userId,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $userIds));
    }
}
