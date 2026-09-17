<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Exceptions\NotificationNotFound;
use App\Domain\Notification\Models\NotificationRecipient;
use Illuminate\Support\Carbon;

/**
 * Marks one notification read, for one person.
 *
 * **Scoped to the signed-in user, and a foreign id is a 404 rather than a 403.** There are no
 * policy classes in this application and `can:` authorises an ability with no model, so «this
 * person may only touch their own mail» cannot be a permission — it is enforced the way the
 * investor portal enforces its own confinement: by the query, and by refusing to admit that
 * anybody else's row exists. A 403 would confirm the id is real.
 */
final readonly class MarkAsRead
{
    public function handle(int $notificationId, int $userId): NotificationRecipient
    {
        $recipient = NotificationRecipient::query()
            ->where('notification_id', $notificationId)
            ->where('user_id', $userId)
            ->first();

        if ($recipient === null) {
            throw NotificationNotFound::make();
        }

        // Idempotent: opening the same notification twice keeps the first time it was read,
        // which is the honest answer and stops a tap from rewriting history.
        if ($recipient->read_at === null) {
            $recipient->read_at = Carbon::now();
            $recipient->save();
        }

        return $recipient;
    }
}
