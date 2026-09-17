<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Actions\PublishNotification;
use App\Domain\Notification\DTOs\PendingNotification;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Models\DeviceToken;
use App\Domain\Notification\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The mailbox endpoints — and the confinement that keeps one to its owner.
 *
 * Arrange - Act - Assert throughout.
 */
class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A real token, not `Sanctum::actingAs` — that produces a `TransientToken` and skips the
     * path the app actually uses (RULES.md §6).
     *
     * @return array<string, string>
     */
    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function employeeWhoCanSeeOrders(): User
    {
        $role = Role::findOrCreate('orders-role-'.uniqid(), 'web');
        $role->givePermissionTo(Permission::findOrCreate(PermissionName::ViewOrders->value, 'web'));

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function publishShortage(): Notification
    {
        return app(PublishNotification::class)->handle(new PendingNotification(
            type: NotificationType::OrderShortage,
            payload: ['order_id' => 7, 'order_code' => 'O7', 'customer_name' => 'أحمد'],
        ));
    }

    public function test_the_list_returns_the_rendered_sentence_and_the_envelope(): void
    {
        // Arrange
        $user = $this->employeeWhoCanSeeOrders();
        $this->publishShortage();

        // Act
        $response = $this->withHeaders($this->auth($user))->getJson('/api/v1/notifications');

        // Assert — the words are built from the frozen payload, not stored.
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.0.type', 'order.shortage')
            ->assertJsonPath('data.0.title', 'طلبية O7 في النواقص')
            ->assertJsonPath('data.0.body', 'العميل: أحمد')
            ->assertJsonPath('data.0.icon', 'warning')
            ->assertJsonPath('data.0.route', '/orders/7')
            ->assertJsonPath('data.0.is_read', false)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_list_is_confined_to_the_signed_in_account(): void
    {
        // Arrange: one recipient, and a stranger who can also read orders but was notified
        // before they existed.
        $this->employeeWhoCanSeeOrders();
        $this->publishShortage();
        $stranger = User::factory()->create(['is_active' => true]);

        // Act
        $response = $this->withHeaders($this->auth($stranger))->getJson('/api/v1/notifications');

        // Assert
        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unread_count_answers_the_bell(): void
    {
        // Arrange
        $user = $this->employeeWhoCanSeeOrders();
        $this->publishShortage();

        // Act
        $response = $this->withHeaders($this->auth($user))->getJson('/api/v1/notifications/unread-count');

        // Assert
        $response->assertOk()->assertJsonPath('data.count', 1);
    }

    public function test_marking_one_read_clears_it_from_the_count(): void
    {
        // Arrange
        $user = $this->employeeWhoCanSeeOrders();
        $notification = $this->publishShortage();

        // Act
        $response = $this->withHeaders($this->auth($user))
            ->postJson("/api/v1/notifications/{$notification->getKey()}/read");

        // Assert
        $response->assertOk()->assertJsonPath('status', true);
        $this->assertDatabaseMissing('notification_recipients', [
            'notification_id' => $notification->getKey(),
            'user_id' => $user->getKey(),
            'read_at' => null,
        ]);
    }

    /**
     * **404, not 403.** A 403 would confirm the id names a real notification.
     */
    public function test_marking_somebody_elses_notification_read_is_a_404_and_leaves_it_alone(): void
    {
        // Arrange
        $owner = $this->employeeWhoCanSeeOrders();
        $notification = $this->publishShortage();
        $stranger = User::factory()->create(['is_active' => true]);

        // Act
        $response = $this->withHeaders($this->auth($stranger))
            ->postJson("/api/v1/notifications/{$notification->getKey()}/read");

        // Assert
        $response->assertNotFound()->assertJsonPath('status', false);
        $this->assertDatabaseHas('notification_recipients', [
            'notification_id' => $notification->getKey(),
            'user_id' => $owner->getKey(),
            'read_at' => null,
        ]);
    }

    public function test_read_all_empties_the_count(): void
    {
        // Arrange
        $user = $this->employeeWhoCanSeeOrders();
        $this->publishShortage();

        // Act
        $response = $this->withHeaders($this->auth($user))->postJson('/api/v1/notifications/read-all');

        // Assert
        $response->assertOk();
        $this->withHeaders($this->auth($user))
            ->getJson('/api/v1/notifications/unread-count')
            ->assertJsonPath('data.count', 0);
    }

    public function test_the_unread_filter_hides_what_has_been_read(): void
    {
        // Arrange
        $user = $this->employeeWhoCanSeeOrders();
        $notification = $this->publishShortage();
        $this->withHeaders($this->auth($user))->postJson("/api/v1/notifications/{$notification->getKey()}/read");

        // Act
        $response = $this->withHeaders($this->auth($user))->getJson('/api/v1/notifications?unread=true');

        // Assert
        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_mailbox_is_closed_to_anyone_not_signed_in(): void
    {
        // Act
        $response = $this->getJson('/api/v1/notifications');

        // Assert
        $response->assertUnauthorized()->assertJsonPath('status', false);
    }

    public function test_registering_the_same_token_again_moves_it_rather_than_duplicating_it(): void
    {
        // Arrange: one counter phone, handed from one employee to the next.
        $first = User::factory()->create(['is_active' => true]);
        $second = User::factory()->create(['is_active' => true]);
        $token = str_repeat('t', 40);

        // Act
        $this->withHeaders($this->auth($first))
            ->postJson('/api/v1/notifications/devices', ['token' => $token, 'platform' => 'android'])
            ->assertOk();

        // The container is reused within one test, so the guard would keep answering with the
        // first user and this would silently assert nothing — RULES.md §6. Every test here that
        // changes who is signed in needs this line.
        $this->app->get('auth')->forgetGuards();

        $this->withHeaders($this->auth($second))
            ->postJson('/api/v1/notifications/devices', ['token' => $token, 'platform' => 'android'])
            ->assertOk();

        // Assert — one row, now the second person's. Two rows would keep delivering the new
        // holder's notifications to the previous one.
        $this->assertSame(1, DeviceToken::query()->count());
        $this->assertDatabaseHas('device_tokens', ['token' => $token, 'user_id' => $second->getKey()]);
    }

    public function test_releasing_a_device_removes_it(): void
    {
        // Arrange
        $user = User::factory()->create(['is_active' => true]);
        $token = str_repeat('t', 40);
        $this->withHeaders($this->auth($user))
            ->postJson('/api/v1/notifications/devices', ['token' => $token, 'platform' => 'ios']);

        // Act
        $response = $this->withHeaders($this->auth($user))
            ->deleteJson('/api/v1/notifications/devices', ['token' => $token]);

        // Assert
        $response->assertOk();
        $this->assertDatabaseMissing('device_tokens', ['token' => $token]);
    }

    public function test_an_unknown_platform_is_refused_with_a_field_error(): void
    {
        // Arrange
        $user = User::factory()->create(['is_active' => true]);

        // Act
        $response = $this->withHeaders($this->auth($user))->postJson('/api/v1/notifications/devices', [
            'token' => str_repeat('t', 40),
            'platform' => 'blackberry',
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonPath('errors.platform.0', 'نوع الجهاز غير صحيح');
    }

    public function test_an_absurd_per_page_is_clamped_rather_than_refused(): void
    {
        // Arrange
        $user = $this->employeeWhoCanSeeOrders();
        $this->publishShortage();

        // Act
        $response = $this->withHeaders($this->auth($user))->getJson('/api/v1/notifications?per_page=100000');

        // Assert — the treatment every other list in this API gives it.
        $response->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_an_administrator_reads_their_own_mailbox_like_anybody_else(): void
    {
        // Arrange
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::findOrCreate(RoleName::Admin->value, 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->publishShortage();

        // Act
        $response = $this->withHeaders($this->auth($admin))->getJson('/api/v1/notifications');

        // Assert — they hold no permission rows, so this only passes because the audience
        // resolver unions the admin role in.
        $response->assertOk()->assertJsonCount(1, 'data');
    }
}
