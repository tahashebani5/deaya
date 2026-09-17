<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Investor\Models\Investor;
use App\Domain\Notification\Actions\PublishNotification;
use App\Domain\Notification\DTOs\PendingNotification;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\NotificationRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Who hears about something, and who deliberately does not.
 *
 * The audience is the whole reason adding a notification is cheap, so it is the part worth
 * testing hardest — a definition is four lines and mostly Arabic, while the resolution below is
 * where a mistake silently reaches nobody, or reaches somebody it should not.
 *
 * Arrange - Act - Assert throughout.
 */
class PublishNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function publish(): PublishNotification
    {
        return app(PublishNotification::class);
    }

    /**
     * A user holding one permission through a role — the ordinary employee shape.
     */
    private function employeeWith(PermissionName $permission): User
    {
        $role = Role::findOrCreate('role-'.uniqid(), 'web');
        $role->givePermissionTo(Permission::findOrCreate($permission->value, 'web'));

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function pendingShortage(?int $causerId = null, ?string $dedupeKey = null): PendingNotification
    {
        return new PendingNotification(
            type: NotificationType::OrderShortage,
            payload: ['order_id' => 1, 'order_code' => 'O1', 'customer_name' => 'أحمد'],
            causerId: $causerId,
            dedupeKey: $dedupeKey,
        );
    }

    public function test_a_permission_audience_reaches_everyone_holding_it(): void
    {
        // Arrange
        $canSeeOrders = $this->employeeWith(PermissionName::ViewOrders);
        $cannot = User::factory()->create(['is_active' => true]);

        // Act
        $notification = $this->publish()->handle($this->pendingShortage());

        // Assert
        $this->assertNotNull($notification);
        $this->assertDatabaseHas('notification_recipients', [
            'notification_id' => $notification->getKey(),
            'user_id' => $canSeeOrders->getKey(),
        ]);
        $this->assertDatabaseMissing('notification_recipients', [
            'notification_id' => $notification->getKey(),
            'user_id' => $cannot->getKey(),
        ]);
    }

    /**
     * **The trap this whole resolver exists to avoid.**
     *
     * `RoleSeeder` grants the administrator nothing — «the gate in AppServiceProvider gives that
     * role everything, so listing permissions here would be duplicated truth». So an admin holds
     * no `role_has_permissions` rows, and the obvious `User::permission(...)` finds none of them:
     * every administrator would silently never receive a single notification, with nothing
     * anywhere to say so.
     */
    public function test_an_administrator_is_notified_although_they_hold_no_permission_rows(): void
    {
        // Arrange: an admin exactly as the seeder makes one — the role, and not one permission.
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::findOrCreate(RoleName::Admin->value, 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Act
        $notification = $this->publish()->handle($this->pendingShortage());

        // Assert
        $this->assertSame(0, $admin->permissions()->count(), 'the admin must hold no permission rows');
        $this->assertDatabaseHas('notification_recipients', [
            'notification_id' => $notification->getKey(),
            'user_id' => $admin->getKey(),
        ]);
    }

    public function test_the_person_who_caused_it_is_not_told_about_their_own_action(): void
    {
        // Arrange: the causer can see orders, so only the exclusion keeps them out.
        $causer = $this->employeeWith(PermissionName::ViewOrders);
        $colleague = $this->employeeWith(PermissionName::ViewOrders);

        // Act
        $notification = $this->publish()->handle($this->pendingShortage(causerId: (int) $causer->getKey()));

        // Assert
        $this->assertDatabaseMissing('notification_recipients', [
            'notification_id' => $notification->getKey(),
            'user_id' => $causer->getKey(),
        ]);
        $this->assertDatabaseHas('notification_recipients', [
            'notification_id' => $notification->getKey(),
            'user_id' => $colleague->getKey(),
        ]);
    }

    public function test_an_identical_notification_inside_the_window_is_not_repeated(): void
    {
        // Arrange
        $this->employeeWith(PermissionName::ViewOrders);

        // Act: the same order bouncing back into shortage an hour later.
        $first = $this->publish()->handle($this->pendingShortage(dedupeKey: 'order.shortage:1'));
        Carbon::setTestNow(Carbon::now()->addHour());
        $second = $this->publish()->handle($this->pendingShortage(dedupeKey: 'order.shortage:1'));

        // Assert
        $this->assertNotNull($first);
        $this->assertNull($second, 'a repeat inside the window must write nothing at all');
        $this->assertSame(1, Notification::query()->count());

        Carbon::setTestNow();
    }

    public function test_the_same_thing_is_heard_again_once_the_window_has_passed(): void
    {
        // Arrange
        $this->employeeWith(PermissionName::ViewOrders);

        // Act
        $this->publish()->handle($this->pendingShortage(dedupeKey: 'order.shortage:1'));
        Carbon::setTestNow(Carbon::now()->addHours(7));
        $later = $this->publish()->handle($this->pendingShortage(dedupeKey: 'order.shortage:1'));

        // Assert — storm control suppresses noise, it does not silence a genuine recurrence.
        $this->assertNotNull($later);
        $this->assertSame(2, Notification::query()->count());

        Carbon::setTestNow();
    }

    public function test_an_audience_holding_nobody_still_records_that_it_happened(): void
    {
        // Arrange: nobody at all can read orders.
        User::factory()->create(['is_active' => true]);

        // Act
        $notification = $this->publish()->handle($this->pendingShortage());

        // Assert — the row is a record that the thing happened; no recipients is ordinary.
        $this->assertNotNull($notification);
        $this->assertSame(0, NotificationRecipient::query()->count());
    }

    public function test_an_inactive_employee_is_not_notified(): void
    {
        // Arrange
        $left = $this->employeeWith(PermissionName::ViewOrders);
        $left->is_active = false;
        $left->save();

        // Act
        $notification = $this->publish()->handle($this->pendingShortage());

        // Assert
        $this->assertDatabaseMissing('notification_recipients', [
            'notification_id' => $notification->getKey(),
            'user_id' => $left->getKey(),
        ]);
    }

    public function test_an_announcement_to_everyone_leaves_investors_out(): void
    {
        // Arrange: one employee, and one investor who happens to have a login.
        $employee = User::factory()->create(['is_active' => true]);
        $investorUser = User::factory()->create(['is_active' => true]);
        Investor::factory()->create(['user_id' => $investorUser->getKey(), 'is_active' => true]);

        // Act
        $notification = $this->publish()->handle(new PendingNotification(
            type: NotificationType::Announcement,
            payload: ['title' => 'اجتماع', 'body' => 'اجتماع الساعة ٤'],
        ));

        // Assert — «الجميع» means every employee, never the people whose money is in the stock.
        $this->assertDatabaseHas('notification_recipients', [
            'notification_id' => $notification->getKey(),
            'user_id' => $employee->getKey(),
        ]);
        $this->assertDatabaseMissing('notification_recipients', [
            'notification_id' => $notification->getKey(),
            'user_id' => $investorUser->getKey(),
        ]);
    }

    public function test_no_push_is_attempted_while_firebase_is_unconfigured(): void
    {
        // Arrange: the state every box is in until the APNs key and Firebase projects exist.
        Queue::fake();
        config()->set('services.fcm.project_id', null);
        config()->set('services.fcm.credentials', null);
        $this->employeeWith(PermissionName::ViewOrders);

        // Act
        $notification = $this->publish()->handle($this->pendingShortage());

        // Assert — the mailbox still works; the push is skipped rather than dispatching jobs
        // that would fail three times each and bury real failures in `failed_jobs`.
        $this->assertSame(1, NotificationRecipient::query()->count());
        Queue::assertNothingPushed();
        $this->assertNotNull($notification);
    }
}
