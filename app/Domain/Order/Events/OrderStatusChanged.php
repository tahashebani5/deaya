<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

use App\Domain\Order\Enums\OrderStatus;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order moved from one status to another.
 *
 * **Fired for every transition, without exception** — including the internal ones nobody is
 * notified about. That is deliberate and it is the difference between this event and its four
 * neighbours in this folder, each of which announces one particular moment: this one announces
 * *the* moment, and leaves «is this worth telling anyone» to whoever is listening.
 *
 * The alternative — filtering at the source, so only the interesting statuses announce — puts a
 * notification-context decision inside `ChangeOrderStatus`, where it would have to be edited
 * again the day a second listener wants a different subset. `NotifyWhenOrderStatusChanges` in
 * the notification context holds that list — named in prose rather than linked, because an
 * import pointing that way is the very dependency this event exists to avoid.
 *
 * Carries both ends of the move rather than only the destination, because «من جاهزة إلى جاري
 * التوصيل» is what a person reads, and re-deriving `from` after the fact means a query against a
 * row that has already changed.
 *
 * Carries the actor so whoever moved the order is not told that they moved it. Null when nobody
 * did: a console command, a seeder.
 *
 * **Its listener is queued and runs after commit**, unlike the money-moving three beside it —
 * see {@see OrderEnteredShortage}, which explains that bargain at length.
 */
final readonly class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(
        public int $orderId,
        public OrderStatus $from,
        public OrderStatus $to,
        public ?int $actorId = null,
    ) {}
}
