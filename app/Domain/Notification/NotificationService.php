<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Domain\Notification\Actions\MarkAllAsRead;
use App\Domain\Notification\Actions\MarkAsRead;
use App\Domain\Notification\Actions\PublishNotification;
use App\Domain\Notification\Actions\RegisterDeviceToken;
use App\Domain\Notification\Actions\ReleaseDeviceToken;
use App\Domain\Notification\Actions\SendAnnouncement;
use App\Domain\Notification\DTOs\AnnouncementData;
use App\Domain\Notification\DTOs\PendingNotification;
use App\Domain\Notification\Enums\DevicePlatform;
use App\Domain\Notification\Models\DeviceToken;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\NotificationRecipient;
use App\Domain\Notification\Queries\NotificationListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The Notification module's only public entry point.
 *
 * Every other context calls this and never `Notification::query()` — that seam is what lets the
 * inside change without a ripple (RULES.md §3). The door, not a place for logic: each method
 * below hands straight to an Action or a Query.
 *
 * **Which direction the dependencies run.** Notification *listens*; nothing listens to it. It
 * may read Order or Inventory through their Services to build a payload, and none of them
 * imports this. Same arrangement as «Orders announces, Investment listens».
 */
final readonly class NotificationService
{
    public function __construct(
        private PublishNotification $publish,
        private MarkAsRead $markAsRead,
        private MarkAllAsRead $markAllAsRead,
        private RegisterDeviceToken $registerDevice,
        private ReleaseDeviceToken $releaseDevice,
        private SendAnnouncement $announce,
        private NotificationListQuery $list,
    ) {}

    /**
     * Tell whoever should know. Returns null when storm control suppressed a repeat.
     */
    public function publish(PendingNotification $pending): ?Notification
    {
        return $this->publish->handle($pending);
    }

    public function sendAnnouncement(AnnouncementData $data, int $senderId): Notification
    {
        return $this->announce->handle($data, $senderId);
    }

    /**
     * @return LengthAwarePaginator<int, NotificationRecipient>
     */
    public function paginateFor(int $userId, bool $unreadOnly = false, int $perPage = 15): LengthAwarePaginator
    {
        return $this->list->paginate($userId, $unreadOnly, $perPage);
    }

    public function unreadCountFor(int $userId): int
    {
        return $this->list->unreadCount($userId);
    }

    public function markAsRead(int $notificationId, int $userId): NotificationRecipient
    {
        return $this->markAsRead->handle($notificationId, $userId);
    }

    public function markAllAsRead(int $userId): int
    {
        return $this->markAllAsRead->handle($userId);
    }

    public function registerDevice(int $userId, string $token, DevicePlatform $platform): DeviceToken
    {
        return $this->registerDevice->handle($userId, $token, $platform);
    }

    public function releaseDevice(int $userId, string $token): bool
    {
        return $this->releaseDevice->handle($userId, $token);
    }
}
