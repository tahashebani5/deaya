<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Models\NotificationRecipient;
use Illuminate\Support\Carbon;

/**
 * Empties one person's unread count in a single statement.
 *
 * A mass update rather than a loop, and unusually for this codebase that is fine: RULES.md §10
 * warns that a mass delete fires no model events and so records nothing — but there is nothing
 * to record here. These rows are outside the audit trail by design, and «قرأت الكل» is one act
 * however many notifications it covers.
 */
final readonly class MarkAllAsRead
{
    /**
     * @return int how many were still unread
     */
    public function handle(int $userId): int
    {
        return NotificationRecipient::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
    }
}
