<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\DTOs\PendingNotification;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\NotificationService;
use App\Domain\Order\Events\OrderEnteredShortage;
use App\Domain\Order\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tells whoever reads orders that one is stuck.
 *
 * ### Queued, and after commit — the opposite of every other listener in this application
 *
 * `PostEarningsWhenOrderIsFinalised` and its two siblings are **synchronous inside the
 * transaction on purpose**, because they move money: «either the status moves and the money is
 * booked or neither happens». Their docblocks say so, and copying them here would be a mistake
 * in both directions —
 *
 * - a failed FCM call would roll back a delivered order's status change, and
 * - a notification would go out for a transaction that then rolled back, telling the whole shop
 *   about a shortage that never happened. Unlike a database row, that cannot be taken back.
 *
 * `$afterCommit` is set explicitly rather than left to the queue connection, which has
 * `after_commit => false` on every connection in `config/queue.php`. Explicit, because this is
 * exactly the kind of side effect RULES.md §8 says not to leave to framework defaults.
 */
final class NotifyWhenOrderEntersShortage implements ShouldQueue
{
    /**
     * Wait for the transaction that produced this to commit before the job is even queued.
     */
    public bool $afterCommit = true;

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(OrderEnteredShortage $event): void
    {
        $order = Order::query()->with('customer')->find($event->orderId);

        // It can have gone between the commit and this job running. Nothing to say about an
        // order that no longer exists.
        if ($order === null) {
            return;
        }

        $this->notifications->publish(PendingNotification::about(
            type: NotificationType::OrderShortage,
            subject: $order,
            // Frozen now, so the sentence still reads correctly after the order is edited or
            // gone — and so the list needs no joins to render.
            payload: [
                'order_id' => (int) $order->getKey(),
                'order_code' => (string) $order->code,
                'customer_name' => (string) ($order->customer?->name ?? ''),
            ],
            causerId: $event->actorId,
            // One notification per order per shift, however many times it bounces in and out of
            // «نواقص» while somebody works on it.
            dedupeKey: 'order.shortage:'.$order->getKey(),
        ));
    }
}
