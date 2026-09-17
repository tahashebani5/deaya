<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

use App\Domain\Order\Actions\SetOrderShortages;
use App\Domain\Order\Enums\ShortageRevision;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * What is missing from an order has just been written — declared, received, or corrected.
 *
 * **One event for all three, because there is only one writer.** {@see SetOrderShortages} is the
 * single place `shortage_quantity` is ever set, and its three callers are entering «نواقص»,
 * leaving it, and correcting it from the order screen. A listener that wants to know "what is
 * missing from this order changed" therefore needs exactly this one hook — and the alternative,
 * three announcements at three call sites, is three places for the fourth caller to forget.
 *
 * **An event rather than a call**, for the reason {@see OrderEnteredShortage} sets out at length:
 * Orders may not depend on anything that reacts to it, and the reactor here is the Shortages
 * section — which reads Orders and must never be read by it.
 *
 * Carries the order, the reason and the actor, and **not what changed**. A listener reads the
 * lines back through `OrderService::shortageLinesFor()` rather than being handed them, because
 * the reconciliation is defined against what is true now, not against a delta: that is what makes
 * running it twice a no-op, which matters because recording a supply writes to the order, the
 * write fires this, and this runs the reconciliation.
 *
 * The reason is the one thing that genuinely cannot be read back afterwards — see
 * {@see ShortageRevision}.
 *
 * **Queued and after commit**, like {@see OrderEnteredShortage} and unlike the three money events
 * beside it. Those are deliberately synchronous inside the transaction because they move money
 * that must move or not at all; a mirror of a number is the opposite bargain, and mirroring a
 * transaction that later rolled back would leave a shortage nobody is short of.
 */
final readonly class OrderShortagesRecorded
{
    use Dispatchable;

    public function __construct(
        public int $orderId,
        public ShortageRevision $reason = ShortageRevision::Corrected,
        public ?int $actorId = null,
    ) {}
}
