<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\DTOs\PendingNotification;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\NotificationService;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Events\OrderStatusChanged;
use App\Domain\Order\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tells whoever reads orders that one has moved — for the moves that are worth an interruption.
 *
 * ### The filter lives here, and that is the point
 *
 * {@see OrderStatusChanged} is fired for every transition, because a transition is a fact about
 * an order and Orders has no business knowing who cares. **This class holds the opinion**: that
 * «جاري التوصيل» is news to the shop and «قيد الطباعة» is news only to the press, who are
 * standing at it. Put that list in `ChangeOrderStatus` and the second listener that wants a
 * different subset has to edit the Orders context to get it.
 *
 * ### Queued, and after commit
 *
 * See {@see NotifyWhenOrderEntersShortage}, which sets out the bargain at length: a failed FCM
 * call must never roll back a delivered order, and a notification about a transaction that then
 * rolled back cannot be un-sent.
 */
final class NotifyWhenOrderStatusChanges implements ShouldQueue
{
    /**
     * Wait for the transaction that produced this to commit before the job is even queued.
     */
    public bool $afterCommit = true;

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(OrderStatusChanged $event): void
    {
        if (! $this->isWorthABell($event->to)) {
            return;
        }

        $order = Order::query()->with('customer')->find($event->orderId);

        // It can have gone between the commit and this job running. Nothing to say about an
        // order that no longer exists.
        if ($order === null) {
            return;
        }

        $this->notifications->publish(PendingNotification::about(
            type: NotificationType::OrderStatusChanged,
            subject: $order,
            // Frozen now, so the sentence still reads correctly after the order is edited or
            // gone — and so the list needs no joins to render. The two statuses are stored as
            // their values rather than their labels; see {@see OrderReachedStatus::label()} for
            // why those alone are looked up at render time.
            payload: [
                'order_id' => (int) $order->getKey(),
                'order_code' => (string) $order->code,
                'customer_name' => (string) ($order->customer?->name ?? ''),
                'from_status' => $event->from->value,
                'to_status' => $event->to->value,
            ],
            causerId: $event->actorId,
            // Keyed on the destination as well as the order, so a parcel that goes out, comes
            // back and goes out again inside one shift is announced once per *kind* of move
            // rather than once per tap.
            dedupeKey: 'order.status:'.$order->getKey().':'.$event->to->value,
        ));
    }

    /**
     * Whether reaching this status is worth interrupting somebody for.
     *
     * **A `match` with every case named and no default**, so the day a sixteenth status is added
     * PHP raises `UnhandledMatchError` in the test suite rather than quietly filing it under
     * «silent». Which side a new status belongs on is a decision, and this makes somebody take
     * it.
     *
     * The line drawn: the shop floor's own steps are silent — the people who work them are
     * looking at the screen where they happen — while everything that changes where the goods
     * *are*, or ends the order, is announced. «نواقص» is silent here only because
     * {@see NotifyWhenOrderEntersShortage} already says more about it than this could, and two
     * bells for one move teaches staff to ignore both.
     */
    private function isWorthABell(OrderStatus $status): bool
    {
        return match ($status) {
            OrderStatus::Ready,
            OrderStatus::OfficePickup,
            OrderStatus::OutForDelivery,
            OrderStatus::Resend,
            OrderStatus::Delivered,
            OrderStatus::Settled,
            OrderStatus::ReturnedCourier,
            OrderStatus::ReturnedCarrier,
            OrderStatus::ReturnedOffice,
            OrderStatus::Cancelled => true,

            OrderStatus::New,
            // **The deposit is a conversation with one customer, not a move of the goods.**
            // Parking an order until its عربون arrives, and being told it has, are the counter's
            // own work and happen on the screen of the person doing them — the line this method
            // draws. The person who *does* need telling is whoever confirms the money reached the
            // account, and they are a narrower audience than «كل من يرى الطلبيات»: a bell for
            // them belongs in its own definition aimed at `orders.deposit.confirm`, not in a
            // broadcast to the whole shop. See Docs/orders/ORDER-DEPOSIT-PLAN.md §٣٫٦.
            OrderStatus::AwaitingDeposit,
            OrderStatus::DepositPaid,
            OrderStatus::ReadyToPrint,
            OrderStatus::Designing,
            OrderStatus::Printing,
            OrderStatus::Manufacturing,
            OrderStatus::Shortage,

            // **The intake pair is silent, and it is the line above that says so.** A bell is
            // for work somebody is in the middle of; nobody is in the middle of a request. It
            // was never accepted, no goods were reserved against it and no bench was booked for
            // it — so refusing one interrupts nothing, and putting one back in the queue is the
            // reviewer's own move on the reviewer's own screen.
            //
            // Both are reachable here only because a refusal can be undone: «بانتظار المراجعة»
            // used to be a status nothing led to.
            OrderStatus::Requested,
            OrderStatus::RequestRejected => false,
        };
    }
}
