<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Identity\Actions\RecordRolePermissionChange;
use App\Domain\Notification\DTOs\AnnouncementData;
use App\Domain\Notification\DTOs\PendingNotification;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Models\Notification;

/**
 * «اجتماع الساعة ٤» — the one notification a person writes.
 *
 * ### It is audited by hand, and that is the point of this class existing at all
 *
 * Notifications are outside the audit trail: they are system consequences, and a log row saying
 * a log row arrived is the recursion `ActivityLog` is exempt from. **An announcement is the
 * opposite** — a deliberate human act, performed under somebody's name, that lands on every
 * employee's phone and cannot be recalled. «من أرسل هذا؟» will be asked.
 *
 * `causer_id` on the notification is not enough on its own, because notifications are pruned by
 * the retention job and the audit trail is not. So an activity row is written explicitly, the
 * same way {@see RecordRolePermissionChange} covers a pivot that
 * has no model of its own.
 *
 * **No storm control.** Two identical announcements ten minutes apart are two deliberate acts;
 * collapsing the second would be a bug, not a kindness. The rate limit on the route is the guard
 * against a double-tapped send button, and it is a different thing.
 */
final readonly class SendAnnouncement
{
    public function __construct(private PublishNotification $publish) {}

    public function handle(AnnouncementData $data, int $senderId): Notification
    {
        $notification = $this->publish->handle(new PendingNotification(
            type: NotificationType::Announcement,
            // The only definition whose text is stored rather than rendered — a human already
            // wrote the sentence, and there is nothing to derive it from. See ManualAnnouncement.
            payload: ['title' => $data->title, 'body' => $data->body, 'role_id' => $data->roleId],
            causerId: $senderId,
        ));

        $this->recordInTheAuditTrail($notification, $data);

        return $notification;
    }

    /**
     * Shaped exactly like {@see RecordRolePermissionChange} — the
     * causer is resolved from the signed-in user by the package, so nothing is set by hand here.
     */
    private function recordInTheAuditTrail(Notification $notification, AnnouncementData $data): void
    {
        activity()
            ->useLog($notification->getMorphClass())
            ->on($notification)
            ->event(AuditEvent::Created->value)
            ->withProperties([
                'announcement' => [
                    'title' => $data->title,
                    // Who it went to, as it was decided rather than as it resolved — a role
                    // renamed or deleted later must not change what this row says was done.
                    'audience' => $data->roleId === null ? 'everyone' : "role:{$data->roleId}",
                    'recipients' => $notification->recipients()->count(),
                ],
            ])
            ->log(AuditEvent::Created->sentence(AuditSubject::Notification->label()));
    }
}
