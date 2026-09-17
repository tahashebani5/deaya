<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/**
 * What became of one push.
 *
 * Exists so that "this device is gone" can be an ordinary return value rather than an exception.
 * It is the single most common non-success outcome — every uninstalled app and every reset phone
 * produces one — and treating it as a failure would fill `failed_jobs` with retries that can
 * never succeed, for devices that no longer exist.
 */
enum FcmSendResult
{
    /** Accepted for delivery. Firebase promises nothing beyond that, and neither do we. */
    case Delivered;

    /**
     * The token is permanently invalid — the app was uninstalled, or the token was reissued.
     * The row must be deleted, not retried.
     */
    case TokenIsDead;

    /** Configured to build and log the payload without sending it. See `FCM_DRY_RUN`. */
    case Skipped;
}
