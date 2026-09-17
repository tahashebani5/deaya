<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Models\DeviceToken;

/**
 * Forgets a phone — on sign-out, and when notifications are switched off.
 *
 * **Sign-out is the call that must not be forgotten**, and it belongs beside where the token
 * itself is cleared rather than in a screen that has to remember. Skip it and a shared shop
 * phone keeps receiving the previous employee's notifications until FCM happens to rotate the
 * token, which may be never.
 *
 * Scoped to the owner: a token string is not a secret worth much, but nothing should be able to
 * unregister somebody else's device by guessing one.
 */
final readonly class ReleaseDeviceToken
{
    /**
     * @return bool whether a device was actually released — false is ordinary, not a failure:
     *              signing out twice, or on a build that never registered, both land here
     */
    public function handle(int $userId, string $token): bool
    {
        return DeviceToken::query()
            ->where('user_id', $userId)
            ->where('token', $token)
            ->delete() > 0;
    }
}
