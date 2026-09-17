<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Investor\Models\Investor;
use App\Domain\Notification\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * «اجتماع الساعة ٤» — the one notification a person writes, and the only guarded endpoint in the
 * feature.
 *
 * The authorization tests here are the ones that matter most: this is the single endpoint in the
 * application that can put a message on every phone in the company.
 *
 * Arrange - Act - Assert throughout.
 */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function administrator(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::findOrCreate(RoleName::Admin->value, 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    /**
     * @return array<string, string>
     */
    private function payload(): array
    {
        return ['title' => 'اجتماع', 'body' => 'اجتماع الساعة ٤ في المكتب'];
    }

    public function test_an_administrator_can_send_one_although_holding_no_permission_rows(): void
    {
        // Arrange
        $admin = $this->administrator();
        $employee = User::factory()->create(['is_active' => true]);

        // Act
        $response = $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/notifications/announcements', $this->payload());

        // Assert — the admin passes through Gate::before, holding nothing.
        $response->assertCreated()->assertJsonPath('status', true);
        $this->assertSame(0, $admin->permissions()->count());
        $this->assertDatabaseHas('notification_recipients', ['user_id' => $employee->getKey()]);
    }

    /**
     * **The assertion that proves this is a permission and not a Gate.** Without it a regression
     * could quietly make announcements administrators-only forever and every other test here
     * would still pass — which is the whole reason the decision went this way.
     */
    public function test_an_employee_granted_the_permission_can_send_one(): void
    {
        // Arrange: an ordinary employee, given the tick box and nothing else.
        $role = Role::findOrCreate('floor-manager', 'web');
        $role->givePermissionTo(Permission::findOrCreate(PermissionName::BroadcastNotifications->value, 'web'));
        $manager = User::factory()->create(['is_active' => true]);
        $manager->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Act
        $response = $this->withHeaders($this->auth($manager))
            ->postJson('/api/v1/notifications/announcements', $this->payload());

        // Assert
        $response->assertCreated();
        $this->assertFalse($manager->isAdmin());
    }

    public function test_an_employee_without_the_permission_is_refused(): void
    {
        // Arrange
        $employee = User::factory()->create(['is_active' => true]);

        // Act
        $response = $this->withHeaders($this->auth($employee))
            ->postJson('/api/v1/notifications/announcements', $this->payload());

        // Assert
        $response->assertForbidden()->assertJsonPath('status', false);
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_it_is_closed_to_anyone_not_signed_in(): void
    {
        // Act
        $response = $this->postJson('/api/v1/notifications/announcements', $this->payload());

        // Assert
        $response->assertUnauthorized();
    }

    public function test_the_sender_does_not_receive_their_own_announcement(): void
    {
        // Arrange
        $admin = $this->administrator();

        // Act
        $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/notifications/announcements', $this->payload())
            ->assertCreated();

        // Assert — a quiet bell after sending is correct, not a delivery that went missing.
        $this->assertDatabaseMissing('notification_recipients', ['user_id' => $admin->getKey()]);
    }

    public function test_investors_never_receive_an_announcement(): void
    {
        // Arrange
        $admin = $this->administrator();
        $employee = User::factory()->create(['is_active' => true]);
        $investorUser = User::factory()->create(['is_active' => true]);
        Investor::factory()->create(['user_id' => $investorUser->getKey(), 'is_active' => true]);

        // Act
        $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/notifications/announcements', $this->payload())
            ->assertCreated();

        // Assert
        $this->assertDatabaseHas('notification_recipients', ['user_id' => $employee->getKey()]);
        $this->assertDatabaseMissing('notification_recipients', ['user_id' => $investorUser->getKey()]);
    }

    public function test_naming_a_role_narrows_it_to_that_role(): void
    {
        // Arrange
        $admin = $this->administrator();
        $role = Role::findOrCreate('press', 'web');
        $inRole = User::factory()->create(['is_active' => true]);
        $inRole->assignRole($role);
        $outside = User::factory()->create(['is_active' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Act
        $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/notifications/announcements', [
                ...$this->payload(),
                'role_id' => $role->getKey(),
            ])
            ->assertCreated();

        // Assert
        $this->assertDatabaseHas('notification_recipients', ['user_id' => $inRole->getKey()]);
        $this->assertDatabaseMissing('notification_recipients', ['user_id' => $outside->getKey()]);
    }

    public function test_the_text_is_echoed_back_verbatim_because_a_person_wrote_it(): void
    {
        // Arrange
        $admin = $this->administrator();
        $reader = User::factory()->create(['is_active' => true]);

        // Act
        $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/notifications/announcements', $this->payload())
            ->assertCreated();
        $this->app->get('auth')->forgetGuards();
        $response = $this->withHeaders($this->auth($reader))->getJson('/api/v1/notifications');

        // Assert — the one definition whose words are stored rather than derived, and the case
        // that proves `route` is nullable.
        $response->assertOk()
            ->assertJsonPath('data.0.title', 'اجتماع')
            ->assertJsonPath('data.0.body', 'اجتماع الساعة ٤ في المكتب')
            ->assertJsonPath('data.0.route', null)
            ->assertJsonPath('data.0.icon', 'announcement');
    }

    public function test_it_is_written_to_the_audit_trail_under_the_senders_name(): void
    {
        // Arrange
        $admin = $this->administrator();
        User::factory()->create(['is_active' => true]);

        // Act
        $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/notifications/announcements', $this->payload())
            ->assertCreated();

        // Assert — «من أرسل هذا؟» has to keep having an answer after the notification itself is
        // pruned, which is why this row is written by hand.
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => 'notification',
            'causer_id' => $admin->getKey(),
            'event' => 'created',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_it_refuses_malformed_input(array $payload, string $field): void
    {
        // Arrange
        $admin = $this->administrator();

        // Act
        $response = $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/notifications/announcements', $payload);

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => [$field]]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'no title' => [['body' => 'نص كافٍ'], 'title'],
            'no body' => [['title' => 'عنوان'], 'body'],
            'title too long' => [['title' => str_repeat('ا', 101), 'body' => 'نص كافٍ'], 'title'],
            'body too long' => [['title' => 'عنوان', 'body' => str_repeat('ا', 501)], 'body'],
            'unknown role' => [['title' => 'عنوان', 'body' => 'نص كافٍ', 'role_id' => 99999], 'role_id'],
        ];
    }
}
