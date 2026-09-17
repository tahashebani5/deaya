<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's copy of one notification, and whether they have read it.
 *
 * Exempt from the audit trail and from soft deletes for the reason {@see Notification} gives at
 * length. These rows are genuinely deleted — by the retention prune, and by the cascade when an
 * account is removed — which is unusual in this schema and is why the foreign keys can rely on
 * `cascadeOnDelete` actually firing.
 *
 * @property int $id
 * @property int $notification_id
 * @property int $user_id
 * @property Carbon|null $read_at
 */
class NotificationRecipient extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
