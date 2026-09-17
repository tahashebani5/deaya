<?php

declare(strict_types=1);

namespace App\Domain\Notification\Support;

use App\Domain\Carrier\Support\NawrisClient;
use App\Domain\Notification\Enums\DevicePlatform;
use App\Domain\Notification\Enums\FcmSendResult;
use App\Domain\Notification\Exceptions\FcmIsNotConfigured;
use App\Domain\Notification\Exceptions\FcmRequestFailed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Every HTTP call to Firebase Cloud Messaging, and nothing else.
 *
 * **Shaped after {@see NawrisClient} on purpose** — one class that
 * is the only file in the application knowing FCM's payload shapes, its whole configuration
 * taken as one array so nothing inside reaches for `config()`, its own log channel, and secrets
 * that never reach it. When Google changes something, one file changes.
 *
 * **FCM HTTP v1, not the legacy server key**, which Google has retired. Authentication is an
 * OAuth2 bearer from a service account — see {@see GoogleServiceAccountToken}.
 *
 * **No `try`/`catch`** (RULES.md §5). A transport or server failure becomes
 * {@see FcmRequestFailed} through `throw()`'s callback; a dead token is read off the body and
 * returned as an ordinary value, because it is an expected outcome rather than a fault.
 */
final readonly class FcmClient
{
    /**
     * Google's own words for "this token is gone". `NOT_FOUND` arrives as a bare 404 on the
     * token; `UNREGISTERED` and `INVALID_ARGUMENT` come back in the error payload. All three
     * mean the same thing operationally: stop pushing to this device, permanently.
     */
    private const DEAD_TOKEN_CODES = ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'];

    /**
     * @param  array<string, mixed>  $config  the `services.fcm` block
     */
    public function __construct(
        private array $config,
        private GoogleServiceAccountToken $token,
    ) {}

    /**
     * Send one notification to one device.
     *
     * @param  int|null  $badge  the recipient's unread count, painted on the iOS app icon. Passed
     *                           in rather than counted here, because this class knows about HTTP
     *                           and not about mailboxes.
     */
    public function send(
        string $deviceToken,
        DevicePlatform $platform,
        string $title,
        string $body,
        ?string $route,
        int $notificationId,
        ?int $badge = null,
    ): FcmSendResult {
        $projectId = (string) ($this->config['project_id'] ?? '');

        if ($projectId === '') {
            throw FcmIsNotConfigured::make();
        }

        $message = $this->message($deviceToken, $platform, $title, $body, $route, $notificationId, $badge);

        // Build it, log it, send nothing. The cheapest possible verification against a project
        // nobody has pushed to yet — the same escape hatch NAWRIS_DRY_RUN provides, and the
        // reason the first live push can be read before it leaves.
        if ((bool) ($this->config['dry_run'] ?? false)) {
            $this->log('dry-run', $message);

            return FcmSendResult::Skipped;
        }

        $response = Http::withToken($this->token->get())
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 5))
            ->timeout((int) ($this->config['timeout'] ?? 15))
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", ['message' => $message]);

        if ($response->successful()) {
            return FcmSendResult::Delivered;
        }

        $status = $response->status();
        $errorCode = (string) $response->json('error.details.0.errorCode', '')
            ?: (string) $response->json('error.status', '');

        if ($status === 404 || in_array($errorCode, self::DEAD_TOKEN_CODES, true)) {
            $this->log('dead-token', ['status' => $status, 'code' => $errorCode]);

            return FcmSendResult::TokenIsDead;
        }

        $this->log('failure', ['status' => $status, 'body' => $response->body()]);

        throw FcmRequestFailed::make($status, $response->body());
    }

    /**
     * The v1 message, with both platforms' override blocks.
     *
     * **The `notification` block is mandatory, not decorative.** A data-only message will not be
     * displayed by iOS while the app is backgrounded or terminated without a Notification
     * Service Extension. Android would tolerate data-only, so both are given the same shape
     * rather than two code paths that diverge the first time somebody edits one.
     *
     * **Nothing sensitive travels here.** A push is readable on a locked screen by whoever is
     * holding the phone, so the title and body carry no amount, no customer phone and no profit
     * figure; the app fetches detail through the authenticated endpoint after the tap. The
     * definitions are where that rule is actually kept — this is only where it is written down.
     *
     * @return array<string, mixed>
     */
    private function message(
        string $deviceToken,
        DevicePlatform $platform,
        string $title,
        string $body,
        ?string $route,
        int $notificationId,
        ?int $badge,
    ): array {
        $message = [
            'token' => $deviceToken,
            'notification' => ['title' => $title, 'body' => $body],
            // Data values must be strings — FCM rejects a message whose data map holds an int.
            'data' => array_filter([
                'notification_id' => (string) $notificationId,
                'route' => $route,
            ], fn ($value) => $value !== null),
        ];

        if ($platform === DevicePlatform::Android) {
            $message['android'] = [
                'priority' => 'high',
                // Must match a channel the app created, or Android 8+ drops the notification
                // silently — no error, no log, nothing on screen.
                'notification' => ['channel_id' => (string) ($this->config['android_channel_id'] ?? 'dayaa_default')],
            ];
        }

        if ($platform === DevicePlatform::Ios) {
            $aps = ['sound' => 'default'];

            if ($badge !== null) {
                $aps['badge'] = $badge;
            }

            $message['apns'] = [
                'headers' => ['apns-priority' => '10'],
                'payload' => ['aps' => $aps],
            ];
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $event, array $context): void
    {
        $channel = (string) ($this->config['log_channel'] ?? '');

        if ($channel === '') {
            return;
        }

        Log::channel($channel)->info("fcm.{$event}", $context);
    }
}
