<?php

declare(strict_types=1);

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Contracts\NotificationChannel;
use App\Domain\Notification\Jobs\DeliverPushNotification;
use App\Domain\Notification\Models\DeviceToken;
use App\Domain\Notification\Models\Notification;

/**
 * Wakes the phones of everyone who has one registered.
 *
 * **One job per device, not one per notification.** A staff-wide announcement reaching thirty
 * phones is thirty jobs, and that is the point: a single dead token retries and fails alone
 * instead of taking the other twenty-nine down with it, and the queue's backoff applies to the
 * device that is actually struggling.
 *
 * **Nothing here is guaranteed to arrive, and nothing depends on it.** The mailbox row is
 * already written by {@see DatabaseChannel} before this runs, so a phone that is off, a token
 * that has expired, or a Firebase project that was never configured all degrade to «the
 * notification is there when they next open the app».
 */
final readonly class PushChannel implements NotificationChannel
{
    /**
     * @param  array<string, mixed>  $config  the `services.fcm` block
     */
    public function __construct(private array $config) {}

    /**
     * @param  list<int>  $userIds
     */
    public function deliver(Notification $notification, array $userIds): void
    {
        if ($userIds === [] || ! $this->isConfigured()) {
            return;
        }

        DeviceToken::query()
            ->whereIn('user_id', $userIds)
            // Chunked because an announcement to every employee is the one case where this set
            // is unbounded — and because dispatching inside the cursor keeps the whole device
            // list from being held in memory at once.
            ->select(['id'])
            ->chunkById(200, function ($tokens) use ($notification): void {
                foreach ($tokens as $token) {
                    DeliverPushNotification::dispatch(
                        (int) $notification->getKey(),
                        (int) $token->getKey(),
                    );
                }
            });
    }

    /**
     * Whether there is a Firebase project to push to at all.
     *
     * **Checked here rather than left to the job to discover.** Until the APNs key and the
     * Firebase projects exist, `FCM_PROJECT_ID` is empty on every box — and without this guard
     * each notification would dispatch a job per device that throws `FcmIsNotConfigured`,
     * retries three times and lands in `failed_jobs`. A few hundred of those would bury the real
     * failures an operator needs to see.
     *
     * So an unconfigured install sends no push and says nothing about it. The mailbox is
     * unaffected: {@see DatabaseChannel} has already written the row, which is the whole reason
     * push is allowed to be best-effort.
     */
    private function isConfigured(): bool
    {
        return (string) ($this->config['project_id'] ?? '') !== ''
            && (string) ($this->config['credentials'] ?? '') !== '';
    }
}
