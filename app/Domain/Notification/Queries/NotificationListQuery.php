<?php

declare(strict_types=1);

namespace App\Domain\Notification\Queries;

use App\Domain\Notification\Models\NotificationRecipient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * One person's mailbox.
 *
 * **Reads the recipient rows, not the notifications.** A notification has no "read" state of its
 * own — that is per person — so the row this account owns is the thing being listed, with its
 * notification eager-loaded beside it.
 *
 * No join, and no `whereHas`: `(user_id, read_at)` covers the filter and the ordering falls out
 * of `notification_id`, which ascends with time because the notifications table is insert-only.
 * That is what keeps the most-opened screen in the feature down to one indexed scan.
 */
final readonly class NotificationListQuery
{
    /**
     * @return LengthAwarePaginator<int, NotificationRecipient>
     */
    public function paginate(int $userId, bool $unreadOnly = false, int $perPage = 15): LengthAwarePaginator
    {
        return NotificationRecipient::query()
            ->with('notification')
            ->where('user_id', $userId)
            ->when($unreadOnly, fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('notification_id')
            ->paginate($perPage);
    }

    /**
     * What the bell's badge shows — and what an iPhone paints on the app icon.
     */
    public function unreadCount(int $userId): int
    {
        return NotificationRecipient::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }
}
