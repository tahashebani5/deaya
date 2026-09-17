<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Enums\DevicePlatform;
use App\Domain\Notification\Models\DeviceToken;
use Illuminate\Support\Carbon;

/**
 * Remembers a phone, so it can be woken.
 *
 * **An upsert on the token, not an insert — and that is a security property.** A counter phone
 * handed from one employee to the next re-registers the same FCM token under a new account. If
 * this created a second row, the previous holder would keep receiving notifications on a device
 * they no longer have: everything from «طلبية O145 في النواقص» to an announcement about a
 * meeting they are no longer at. The unique index on `token` makes that impossible; this action
 * is the half that reassigns rather than failing on it.
 *
 * FCM also rotates tokens by itself, so the app re-registers on every rotation and this runs far
 * more often than a person signs in.
 */
final readonly class RegisterDeviceToken
{
    public function handle(int $userId, string $token, DevicePlatform $platform): DeviceToken
    {
        $device = DeviceToken::query()->where('token', $token)->first() ?? new DeviceToken;

        $device->user_id = $userId;
        $device->token = $token;
        $device->platform = $platform;
        $device->last_used_at = Carbon::now();
        $device->save();

        return $device;
    }
}
