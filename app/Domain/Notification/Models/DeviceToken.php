<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Notification\Enums\DevicePlatform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A device that has asked to be woken.
 *
 * **Hard deleted — the one model in this application that is**, and the migration explains why
 * at length: an FCM token is a routing address, not a record, and a dead address kept "for
 * history" costs a failed queued job every time something is published. `ModelConventionsTest`
 * exempts it alongside the two notification tables.
 *
 * A token belongs to exactly one account at a time. The unique index on `token` is what enforces
 * that, so a shared counter phone moving between employees updates this row rather than growing
 * a second one — see RegisterDeviceToken.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property DevicePlatform $platform
 * @property Carbon|null $last_used_at
 */
class DeviceToken extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
