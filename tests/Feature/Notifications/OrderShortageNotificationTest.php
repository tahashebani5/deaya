<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Listeners\NotifyWhenOrderEntersShortage;
use App\Domain\Notification\Models\Notification;
use App\Domain\Order\Actions\ChangeOrderStatus;
use App\Domain\Order\Enums\OrderFlow;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Events\OrderEnteredShortage;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The first notification, end to end: an order enters «نواقص» and the people who read orders
 * are told.
 *
 * The listener is queued and deferred to after commit, so these tests run it directly rather
 * than through the queue — what is being proved is that the transition announces, and that the
 * listener turns the announcement into the right notification for the right people.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderShortageNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function employeeWhoCanSeeOrders(): User
    {
        $role = Role::findOrCreate('orders-reader-'.uniqid(), 'web');
        $role->givePermissionTo(Permission::findOrCreate(PermissionName::ViewOrders->value, 'web'));

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    public function test_moving_an_order_into_shortage_announces_it(): void
    {
        // Arrange
        Event::fake([OrderEnteredShortage::class]);

        // The road is normally stamped from the order's lines when it is created; set directly
        // here because what is under test is the transition announcing, not how a flow is chosen.
        // «جديدة» is the only status the map allows into «نواقص» — the shortage is discovered
        // when the order is first checked against the shelf, not later on the press.
        $order = Order::factory()->create([
            'status' => OrderStatus::New,
            'production_flow' => OrderFlow::Standard,
        ]);
        $item = OrderItem::factory()->create(['order_id' => $order->getKey()]);
        $actor = User::factory()->create(['is_active' => true]);

        // Act — «كم الناقص» is a question about a size, so the move carries a figure per line
        // and the domain insists on at least one of them.
        app(ChangeOrderStatus::class)(
            $order,
            OrderStatus::Shortage,
            'لا توجد مواد',
            $actor,
            ["shortage_{$item->getKey()}" => '100'],
        );

        // Assert — announced, not acted on. The listener is somebody else's business.
        Event::assertDispatched(
            OrderEnteredShortage::class,
            fn (OrderEnteredShortage $event) => $event->orderId === (int) $order->getKey()
                && $event->actorId === (int) $actor->getKey(),
        );
    }

    public function test_the_listener_tells_everyone_who_reads_orders(): void
    {
        // Arrange
        $reader = $this->employeeWhoCanSeeOrders();
        $order = Order::factory()->create();

        // Act
        app(NotifyWhenOrderEntersShortage::class)->handle(
            new OrderEnteredShortage((int) $order->getKey()),
        );

        // Assert
        $notification = Notification::query()->firstOrFail();
        $this->assertSame(NotificationType::OrderShortage, $notification->type);
        $this->assertSame('order', $notification->subject_type);
        $this->assertSame((int) $order->getKey(), $notification->subject_id);
        $this->assertDatabaseHas('notification_recipients', [
            'notification_id' => $notification->getKey(),
            'user_id' => $reader->getKey(),
        ]);
    }

    public function test_the_payload_is_frozen_so_the_sentence_survives_the_order_changing(): void
    {
        // Arrange
        $this->employeeWhoCanSeeOrders();
        $order = Order::factory()->create();
        $originalCode = (string) $order->code;

        // Act
        app(NotifyWhenOrderEntersShortage::class)->handle(
            new OrderEnteredShortage((int) $order->getKey()),
        );
        $order->code = 'CHANGED';
        $order->save();

        // Assert — a snapshot, not a pointer: the notification still reads as it did when it
        // was written, and needed no join to say so.
        $notification = Notification::query()->firstOrFail();
        $this->assertSame($originalCode, $notification->payload['order_code']);
    }

    public function test_an_order_deleted_before_the_job_runs_notifies_nobody(): void
    {
        // Arrange
        $this->employeeWhoCanSeeOrders();

        // Act — the id of an order that is not there, which is what a backed-up queue produces.
        app(NotifyWhenOrderEntersShortage::class)->handle(new OrderEnteredShortage(999999));

        // Assert — nothing to say, and nothing thrown.
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_the_listener_waits_for_the_transaction_to_commit(): void
    {
        // Arrange & Act
        $listener = app(NotifyWhenOrderEntersShortage::class);

        // Assert — stated as a test because it is the one property separating this listener from
        // its three money-moving neighbours, and nothing else would catch it being dropped.
        $this->assertTrue($listener->afterCommit);
        $this->assertInstanceOf(ShouldQueue::class, $listener);
    }
}
