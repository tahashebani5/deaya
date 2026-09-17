<?php

declare(strict_types=1);

namespace App\Domain\Notification\Jobs;

use App\Domain\Notification\Contracts\NotificationDefinition;
use App\Domain\Notification\Enums\FcmSendResult;
use App\Domain\Notification\Models\DeviceToken;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\NotificationRecipient;
use App\Domain\Notification\Support\FcmClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * One push, to one device.
 *
 * Queued, because Firebase is somebody else's server: the order status change that produced this
 * has long since committed, and nothing about a slow or unreachable FCM may reach back into it.
 *
 * **Takes ids rather than models.** A serialised model in a job payload is a snapshot that goes
 * stale on the queue; ids force the job to read the world as it is when it actually runs, which
 * is what makes the two "it is already gone" cases below correct rather than lucky.
 */
class DeliverPushNotification implements ShouldQueue
{
    use Queueable;

    /**
     * Three attempts with growing gaps. A push that has failed three times over ten minutes is
     * not going to succeed on the fourth, and the job belongs in `failed_jobs` where somebody
     * can see it rather than in a retry loop nobody is watching.
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 300];

    public function __construct(
        private readonly int $notificationId,
        private readonly int $deviceTokenId,
    ) {}

    public function handle(FcmClient $fcm): void
    {
        $device = DeviceToken::find($this->deviceTokenId);
        $notification = Notification::find($this->notificationId);

        // Either can legitimately have vanished between dispatch and execution: the device by
        // being released on logout, the notification by the retention prune on a queue that had
        // backed up. Neither is a failure — there is simply nothing left to deliver.
        if ($device === null || $notification === null) {
            return;
        }

        /** @var NotificationDefinition $definition */
        $definition = app($notification->type->definition());
        $rendered = $definition->render($notification->payload);

        $result = $fcm->send(
            deviceToken: $device->token,
            platform: $device->platform,
            title: $rendered->title,
            body: $rendered->body,
            route: $rendered->route,
            notificationId: (int) $notification->getKey(),
            badge: $this->unreadCountFor((int) $device->user_id),
        );

        // Permanently invalid, so the row goes rather than the job failing. Retrying an
        // uninstalled app forever is how `failed_jobs` fills with noise that hides real trouble.
        if ($result === FcmSendResult::TokenIsDead) {
            $device->delete();

            return;
        }

        $device->last_used_at = Carbon::now();
        $device->save();
    }

    /**
     * What the iOS app icon should show — this person's whole unread count, not this
     * notification's place in it.
     *
     * Counted here, at send time, because the app cannot count while it is not running. One
     * indexed scan on `(user_id, read_at)`, which is half of why that index exists.
     */
    private function unreadCountFor(int $userId): int
    {
        return NotificationRecipient::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }
}
