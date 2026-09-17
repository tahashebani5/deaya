<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Listeners\NotifyWhenOrderStatusChanges;
use App\Domain\Notification\Models\Notification;
use App\Domain\Order\Actions\ChangeOrderStatus;
use App\Domain\Order\Enums\OrderFlow;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Events\OrderStatusChanged;
use App\Domain\Order\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * An order moves, and the people who read orders are told — but only about the moves that mean
 * something to somebody standing away from the screen it happened on.
 *
 * **The split under test is the whole design.** `ChangeOrderStatus` announces *every* transition,
 * because a transition is a fact and Orders does not know who cares about it; the listener is
 * what decides that «جاري التوصيل» is worth a bell and «قيد التصميم» is not. Testing them apart
 * is what keeps that seam honest — a future context can listen for the internal steps without
 * anything here changing.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderStatusNotificationTest extends TestCase
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

    public function test_a_transition_announces_where_it_came_from_and_where_it_went(): void
    {
        // Arrange — a step nobody is notified about on purpose, chosen precisely for that: the
        // announcement is a fact about the order, not a decision about who hears it.
        Event::fake([OrderStatusChanged::class]);

        $order = Order::factory()->create([
            'status' => OrderStatus::Printing,
            'production_flow' => OrderFlow::Standard,
        ]);
        $actor = User::factory()->create(['is_active' => true]);

        // Act — back a step to the artwork, which moves no stock and no money.
        app(ChangeOrderStatus::class)($order, OrderStatus::Designing, null, $actor);

        // Assert
        Event::assertDispatched(
            OrderStatusChanged::class,
            fn (OrderStatusChanged $event) => $event->orderId === (int) $order->getKey()
                && $event->from === OrderStatus::Printing
                && $event->to === OrderStatus::Designing
                && $event->actorId === (int) $actor->getKey(),
        );
    }

    public function test_a_milestone_tells_everyone_who_reads_orders(): void
    {
        // Arrange
        $reader = $this->employeeWhoCanSeeOrders();
        $order = Order::factory()->create();

        // Act
        app(NotifyWhenOrderStatusChanges::class)->handle(new OrderStatusChanged(
            (int) $order->getKey(),
            OrderStatus::Ready,
            OrderStatus::OutForDelivery,
        ));

        // Assert
        $notification = Notification::query()->firstOrFail();
        $this->assertSame(NotificationType::OrderStatusChanged, $notification->type);
        $this->assertSame('order', $notification->subject_type);
        $this->assertSame((int) $order->getKey(), $notification->subject_id);
        $this->assertDatabaseHas('notification_recipients', [
            'notification_id' => $notification->getKey(),
            'user_id' => $reader->getKey(),
        ]);
    }

    public function test_an_internal_production_step_tells_nobody(): void
    {
        // Arrange
        $this->employeeWhoCanSeeOrders();
        $order = Order::factory()->create();

        // Act — the press picking the job up is not news to anyone who is not at the press.
        app(NotifyWhenOrderStatusChanges::class)->handle(new OrderStatusChanged(
            (int) $order->getKey(),
            OrderStatus::ReadyToPrint,
            OrderStatus::Printing,
        ));

        // Assert
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_shortage_is_left_to_the_notification_that_already_covers_it(): void
    {
        // Arrange
        $this->employeeWhoCanSeeOrders();
        $order = Order::factory()->create();

        // Act
        app(NotifyWhenOrderStatusChanges::class)->handle(new OrderStatusChanged(
            (int) $order->getKey(),
            OrderStatus::New,
            OrderStatus::Shortage,
        ));

        // Assert — one bell per event. `order.shortage` says more about it than this one could,
        // and two notifications for one move is the fastest way to teach staff to ignore both.
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_the_payload_is_frozen_so_the_sentence_survives_the_order_changing(): void
    {
        // Arrange
        $this->employeeWhoCanSeeOrders();
        $order = Order::factory()->create();
        $originalCode = (string) $order->code;

        // Act
        app(NotifyWhenOrderStatusChanges::class)->handle(new OrderStatusChanged(
            (int) $order->getKey(),
            OrderStatus::Ready,
            OrderStatus::OutForDelivery,
        ));
        $order->code = 'CHANGED';
        $order->save();

        // Assert — a snapshot, not a pointer.
        $notification = Notification::query()->firstOrFail();
        $this->assertSame($originalCode, $notification->payload['order_code']);
        $this->assertSame(OrderStatus::OutForDelivery->value, $notification->payload['to_status']);
        $this->assertSame(OrderStatus::Ready->value, $notification->payload['from_status']);
    }

    public function test_the_same_order_reaching_the_same_status_twice_is_one_notification(): void
    {
        // Arrange
        $this->employeeWhoCanSeeOrders();
        $order = Order::factory()->create();
        $event = new OrderStatusChanged(
            (int) $order->getKey(),
            OrderStatus::Ready,
            OrderStatus::OutForDelivery,
        );

        // Act — a parcel that goes out, comes back and goes out again inside one shift.
        app(NotifyWhenOrderStatusChanges::class)->handle($event);
        app(NotifyWhenOrderStatusChanges::class)->handle($event);

        // Assert
        $this->assertSame(1, Notification::query()->count());
    }

    public function test_an_order_deleted_before_the_job_runs_notifies_nobody(): void
    {
        // Arrange
        $this->employeeWhoCanSeeOrders();

        // Act — the id of an order that is not there, which is what a backed-up queue produces.
        app(NotifyWhenOrderStatusChanges::class)->handle(new OrderStatusChanged(
            999999,
            OrderStatus::Ready,
            OrderStatus::OutForDelivery,
        ));

        // Assert — nothing to say, and nothing thrown.
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_the_listener_waits_for_the_transaction_to_commit(): void
    {
        // Arrange & Act
        $listener = app(NotifyWhenOrderStatusChanges::class);

        // Assert — the one property separating this listener from its money-moving neighbours.
        $this->assertTrue($listener->afterCommit);
        $this->assertInstanceOf(ShouldQueue::class, $listener);
    }
}
