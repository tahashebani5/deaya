<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * More material has left the shelf for an order that was already fulfilled — a run gone wrong,
 * and the bags spoiled with it.
 *
 * **A second draw is a second purchase**, and the owner settled it in one line on 2026-09-06:
 * «هي من لما تكون جاهزة وبينخصم من المخزون خلاص اعطيه حقاته». A kilo that leaves is sold,
 * whatever became of it afterwards — the press wanted it, the press pays for it, and whoever
 * financed it is owed the same margin as on the first draw.
 *
 * **Carries the movement, not the order, and that is the whole difference from
 * {@see OrderStockDrawn}.** That event says «every line's draw is now final», and its listener
 * recomputes each line from the draw the line currently points at. Scrap points at nothing: it is
 * an extra movement beside a line whose own draw is unchanged, and recomputing the line would
 * replace the first payment with the second instead of adding to it. So this names the movement
 * and is paid against the movement.
 */
final readonly class OrderScrapDrawn
{
    use Dispatchable;

    public function __construct(public int $orderId, public int $stockMovementId) {}
}
