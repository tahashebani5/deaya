<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Listeners;

use App\Domain\Order\Events\OrderShortagesRecorded;
use App\Domain\Shortage\Actions\SyncShortagesFromOrder;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Mirrors what an order says is missing into the shortages section.
 *
 * **One listener covers all three ways that number moves** — declaring a shortage, receiving
 * against it, and correcting it from the order screen — because `SetOrderShortages` is the single
 * writer and so fires a single event. That is the whole cost of the integration, and it is why no
 * other line was added to `Domain/Order`.
 *
 * **Queued and after commit**, the `NotifyWhenOrderEntersShortage` bargain rather than the one the
 * three money listeners make. Those are synchronous inside the transaction on purpose — either
 * the status moves and the money is booked or neither happens. This is a mirror: mirroring a
 * transaction that then rolled back would leave the board showing a shortage nobody is short of,
 * and the reconciliation is idempotent, so running it a moment later costs nothing.
 *
 * `$afterCommit` is set explicitly rather than left to the queue connection, which has
 * `after_commit => false` on every connection in `config/queue.php` — exactly the kind of side
 * effect RULES.md §8 says not to leave to a framework default.
 */
final class SyncWhenOrderShortagesChange implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly SyncShortagesFromOrder $sync) {}

    public function handle(OrderShortagesRecorded $event): void
    {
        ($this->sync)($event->orderId, $event->reason, $event->actorId);
    }
}
