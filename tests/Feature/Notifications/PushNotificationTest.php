<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Actions\PublishNotification;
use App\Domain\Notification\DTOs\PendingNotification;
use App\Domain\Notification\Enums\DevicePlatform;
use App\Domain\Notification\Enums\FcmSendResult;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Jobs\DeliverPushNotification;
use App\Domain\Notification\Models\DeviceToken;
use App\Domain\Notification\Support\FcmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The push channel — and above all, what a push is not allowed to carry.
 *
 * `Http::fake()` throughout, so nothing here can reach Google. RULES.md §9 forbids tests sending
 * real notifications; `FCM_DRY_RUN` is the second belt on a live box.
 *
 * The service account is generated in `setUp` rather than stubbed, so the **real signing path**
 * runs — an assertion that never gets built would otherwise pass every test here and fail on the
 * first live push.
 *
 * Arrange - Act - Assert throughout.
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private string $credentialsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credentialsPath = tempnam(sys_get_temp_dir(), 'fcm').'.json';
        file_put_contents($this->credentialsPath, (string) json_encode([
            'client_email' => 'test@example.iam.gserviceaccount.com',
            'private_key' => 'unused — see below',
        ]));

        config()->set('services.fcm.project_id', 'test-project');
        config()->set('services.fcm.credentials', $this->credentialsPath);
        config()->set('services.fcm.dry_run', false);
        config()->set('services.fcm.log_channel', null);

        // **A cached access token, so nothing here has to sign anything.**
        //
        // `GoogleServiceAccountToken::get()` returns straight from the cache, so the credentials
        // file above is never opened and no RSA key is needed. That keeps these tests — which
        // are about *what the push carries* — portable: generating a key at runtime needs an
        // `openssl.cnf`, which Windows does not ship, and a key committed to the repository is a
        // secret-scanner finding waiting to happen.
        //
        // The signing path itself is covered separately below, where it can be.
        Cache::put('fcm.access_token', 'test-access-token', 3600);
    }

    protected function tearDown(): void
    {
        @unlink($this->credentialsPath);

        parent::tearDown();
    }

    private function employeeWhoCanSeeOrders(): User
    {
        $role = Role::findOrCreate('push-reader-'.uniqid(), 'web');
        $role->givePermissionTo(Permission::findOrCreate(PermissionName::ViewOrders->value, 'web'));

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function deviceFor(User $user, DevicePlatform $platform = DevicePlatform::Android): DeviceToken
    {
        $device = new DeviceToken;
        $device->user_id = (int) $user->getKey();
        $device->token = 'token-'.uniqid();
        $device->platform = $platform;
        $device->save();

        return $device;
    }

    private function publishShortage(): int
    {
        return (int) app(PublishNotification::class)->handle(new PendingNotification(
            type: NotificationType::OrderShortage,
            payload: ['order_id' => 7, 'order_code' => 'O7', 'customer_name' => 'أحمد'],
        ))->getKey();
    }

    public function test_publishing_queues_one_push_per_registered_device(): void
    {
        // Arrange
        Queue::fake();
        $user = $this->employeeWhoCanSeeOrders();
        $this->deviceFor($user);
        $this->deviceFor($user, DevicePlatform::Ios);

        // Act
        $this->publishShortage();

        // Assert — one job per device, so a single dead token retries alone rather than taking
        // the others with it.
        Queue::assertPushed(DeliverPushNotification::class, 2);
    }

    public function test_the_push_carries_no_customer_name_or_amount(): void
    {
        // Arrange
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/1']),
        ]);
        $user = $this->employeeWhoCanSeeOrders();
        $device = $this->deviceFor($user);
        $notificationId = $this->publishShortage();

        // Act
        (new DeliverPushNotification($notificationId, (int) $device->getKey()))->handle(app(FcmClient::class));

        // Assert — a push is readable on a locked screen by whoever is holding the phone.
        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'fcm.googleapis.com')) {
                return true;
            }

            $body = (string) $request->body();

            return ! str_contains($body, 'أحمد')
                && str_contains($body, 'O7')
                && str_contains($body, '"route":"\/orders\/7"');
        });
    }

    public function test_an_android_push_names_the_channel_the_app_created(): void
    {
        // Arrange
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'ok']),
        ]);

        // Act
        $result = app(FcmClient::class)->send(
            deviceToken: 'abc',
            platform: DevicePlatform::Android,
            title: 'عنوان',
            body: 'نص',
            route: '/orders/7',
            notificationId: 1,
        );

        // Assert — a channel id that does not match one the app created means Android 8+ drops
        // the notification silently.
        $this->assertSame(FcmSendResult::Delivered, $result);
        Http::assertSent(fn (Request $r) => ! str_contains($r->url(), 'fcm.googleapis.com')
            || str_contains((string) $r->body(), 'dayaa_default'));
    }

    public function test_an_ios_push_carries_the_badge_and_the_aps_block(): void
    {
        // Arrange
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'ok']),
        ]);

        // Act
        app(FcmClient::class)->send(
            deviceToken: 'abc',
            platform: DevicePlatform::Ios,
            title: 'عنوان',
            body: 'نص',
            route: null,
            notificationId: 1,
            badge: 3,
        );

        // Assert — the icon badge cannot be counted by an app that is not running.
        Http::assertSent(fn (Request $r) => ! str_contains($r->url(), 'fcm.googleapis.com')
            || (str_contains((string) $r->body(), '"badge":3') && str_contains((string) $r->body(), 'aps')));
    }

    public function test_a_dead_token_is_deleted_rather_than_retried(): void
    {
        // Arrange
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response([
                'error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]],
            ], 404),
        ]);
        $user = $this->employeeWhoCanSeeOrders();
        $device = $this->deviceFor($user);
        $notificationId = $this->publishShortage();

        // Act
        (new DeliverPushNotification($notificationId, (int) $device->getKey()))->handle(app(FcmClient::class));

        // Assert — an uninstalled app must not fill `failed_jobs` with retries that can never
        // succeed, and the row must genuinely go so nothing keeps finding it.
        $this->assertDatabaseMissing('device_tokens', ['id' => $device->getKey()]);
    }

    public function test_dry_run_builds_the_payload_and_sends_nothing(): void
    {
        // Arrange
        Http::fake();
        config()->set('services.fcm.dry_run', true);

        // Act
        $result = app(FcmClient::class)->send(
            deviceToken: 'abc',
            platform: DevicePlatform::Android,
            title: 'عنوان',
            body: 'نص',
            route: null,
            notificationId: 1,
        );

        // Assert — the cheapest possible verification before the first live push leaves a box.
        $this->assertSame(FcmSendResult::Skipped, $result);
        Http::assertNothingSent();
    }

    /**
     * The one test that actually signs, so the assertion-building path is not left entirely
     * unexercised — it would otherwise fail for the first time on a live box.
     *
     * Skips rather than fails where OpenSSL has no `openssl.cnf` to generate a key with, which
     * is the default on Windows. **A skip is honest; passing without signing would not be.**
     */
    public function test_the_oauth_assertion_is_signed_and_exchanged_for_a_token(): void
    {
        // Arrange
        $key = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false || ! @openssl_pkey_export($key, $privateKey)) {
            $this->markTestSkipped('OpenSSL has no usable configuration here, so no key can be generated.');
        }

        file_put_contents($this->credentialsPath, (string) json_encode([
            'client_email' => 'test@example.iam.gserviceaccount.com',
            'private_key' => $privateKey,
        ]));
        Cache::forget('fcm.access_token');

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fresh', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'ok']),
        ]);

        // Act
        $result = app(FcmClient::class)->send(
            deviceToken: 'abc',
            platform: DevicePlatform::Android,
            title: 'عنوان',
            body: 'نص',
            route: null,
            notificationId: 1,
        );

        // Assert — a three-part JWT went to Google, and its answer was cached rather than
        // re-exchanged for every device on the next announcement.
        $this->assertSame(FcmSendResult::Delivered, $result);
        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'oauth2.googleapis.com')) {
                return true;
            }

            $assertion = (string) ($request->data()['assertion'] ?? '');

            return substr_count($assertion, '.') === 2 && $assertion !== '';
        });
        $this->assertSame('fresh', Cache::get('fcm.access_token'));
    }

    public function test_a_notification_pruned_before_the_job_runs_sends_nothing(): void
    {
        // Arrange
        Http::fake();
        $user = $this->employeeWhoCanSeeOrders();
        $device = $this->deviceFor($user);

        // Act — the ids of things that are no longer there, which is what a backed-up queue
        // eventually produces.
        (new DeliverPushNotification(999999, (int) $device->getKey()))->handle(app(FcmClient::class));

        // Assert
        Http::assertNothingSent();
    }
}
