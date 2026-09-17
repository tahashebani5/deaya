<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/**
 * What kind of device a push is addressed to.
 *
 * Kept because FCM's message carries platform-specific override blocks that are not
 * interchangeable: an iPhone needs `apns.payload.aps` for its sound and badge, an Android needs
 * a `channel_id` that must match one the app created or the notification is dropped silently.
 * A registration token cannot be asked which it is, so the device says so when it registers.
 */
enum DevicePlatform: string
{
    case Android = 'android';
    case Ios = 'ios';
    case Web = 'web';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $platform) => $platform->value, self::cases());
    }
}
