<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order could not be filled from the shelf and is now waiting on a person.
 *
 * **An event rather than a call**, for the reason {@see OrderStockDrawn} sets out at length:
 * Orders may not depend on anything that reacts to it, and here the reactor is the notification
 * centre — which reads Orders and must never be read by it.
 *
 * Carries the actor so whoever moved the order is not told that they moved it. Null when nobody
 * did: a console command, a seeder.
 *
 * Unlike its three neighbours in this folder, **the listener on this one is queued and runs
 * after commit**. Those three move money and are deliberately synchronous inside the
 * transaction; a notification is the opposite bargain, and one about a transaction that later
 * rolled back must never have been sent at all.
 */
final readonly class OrderEnteredShortage
{
    use Dispatchable;

    public function __construct(
        public int $orderId,
        public ?int $actorId = null,
    ) {}
}
