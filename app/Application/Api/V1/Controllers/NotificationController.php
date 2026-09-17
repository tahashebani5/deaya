<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Requests\Notification\RegisterDeviceRequest;
use App\Application\Api\V1\Requests\Notification\ReleaseDeviceRequest;
use App\Application\Api\V1\Requests\Notification\SendAnnouncementRequest;
use App\Application\Api\V1\Resources\NotificationResource;
use App\Application\Controller;
use App\Domain\Notification\NotificationService;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications
 *
 * Everything this account has been told, and the bell's unread count.
 *
 * **There is no id for somebody else's mailbox here, and that is the security.** This
 * application has no policy classes; `can:` authorises an ability with no model, and an
 * administrator passes every ability unconditionally — so «this person reads only their own
 * mail» cannot be expressed as a permission. It is enforced by every query below being scoped to
 * the signed-in user, and by a foreign notification id answering **404 rather than 403**, which
 * would confirm the id names something real. The same shape the investor portal uses.
 *
 * Reading is behind no permission at all: every account has a mailbox. Only sending an
 * announcement is guarded.
 */
class NotificationController extends Controller
{
    use ResponseTrait;

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * My notifications
     *
     * Newest first. Pass `unread=true` for only the ones not yet opened.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        return $this->successWithPagination(
            NotificationResource::collection($this->notifications->paginateFor(
                (int) $request->user()->getKey(),
                $request->boolean('unread'),
                $perPage,
            )),
        );
    }

    /**
     * How many I have not read
     *
     * What the bell's badge shows. Kept as its own endpoint rather than a field on the list,
     * because the app asks for it far more often than it opens the list — on resume, and after
     * a push arrives.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success([
            'count' => $this->notifications->unreadCountFor((int) $request->user()->getKey()),
        ]);
    }

    /**
     * Mark one as read
     *
     * Idempotent: the first time it was opened is kept. A notification belonging to somebody
     * else answers 404.
     */
    public function markAsRead(Request $request, int $notification): JsonResponse
    {
        $this->notifications->markAsRead($notification, (int) $request->user()->getKey());

        return $this->successMessage('تم تعليم الإشعار كمقروء');
    }

    /**
     * Mark everything as read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $this->notifications->markAllAsRead((int) $request->user()->getKey());

        return $this->successMessage('تم تعليم كل الإشعارات كمقروءة');
    }

    /**
     * Register this device for push
     *
     * Called on sign-in, when notifications are switched on, and whenever FCM rotates the token.
     * Re-registering a token already known moves it to this account rather than creating a
     * second row — which is what stops a shared shop phone delivering the previous employee's
     * notifications.
     */
    public function registerDevice(RegisterDeviceRequest $request): JsonResponse
    {
        $this->notifications->registerDevice(
            (int) $request->user()->getKey(),
            $request->token(),
            $request->platform(),
        );

        return $this->successMessage('تم تسجيل الجهاز');
    }

    /**
     * Release this device
     *
     * **Call this on sign-out**, before the token is cleared from the phone's own storage.
     * Releasing a device that was never registered is a success, not an error.
     */
    public function releaseDevice(ReleaseDeviceRequest $request): JsonResponse
    {
        $this->notifications->releaseDevice((int) $request->user()->getKey(), $request->token());

        return $this->successMessage('تم إلغاء تسجيل الجهاز');
    }

    /**
     * Send an announcement to staff
     *
     * «اجتماع الساعة ٤». Reaches every active employee, or one role. **Investors never receive
     * it.** It cannot be recalled, cannot be scheduled, and the sender does not receive their
     * own — a quiet bell after sending is correct, not a delivery that went missing.
     *
     * Behind `notifications.broadcast` and a rate limit: this is the one endpoint that can put a
     * message on every phone in the company.
     */
    public function sendAnnouncement(SendAnnouncementRequest $request): JsonResponse
    {
        $notification = $this->notifications->sendAnnouncement(
            $request->announcement(),
            (int) $request->user()->getKey(),
        );

        return $this->created(
            ['id' => $notification->getKey(), 'recipients' => $notification->recipients()->count()],
            'تم إرسال الإشعار',
        );
    }
}
