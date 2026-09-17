<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An archived order has been brought back and has taken its material off the shelf **a second
 * time** — one movement, on one line, from today's layers.
 *
 * **A second draw is a second purchase**, the rule {@see OrderScrapDrawn} already states in the
 * owner's words: «هي من لما تكون جاهزة وبينخصم من المخزون خلاص اعطيه حقاته». A kilo that leaves
 * is sold, and the restore's draw eats whatever FIFO puts in front of it — an investor's priced
 * layer among them. Announcing nothing would take his goods for free.
 *
 * **Carries the movement, not the order, and that is the whole difference from
 * {@see OrderStockDrawn}.** That event says «every line's draw is now final», and its listener
 * recomputes each line from the draw the line currently points at, *replacing* what was paid for
 * that line before — right for a restatement, which corrects one draw, and wrong here. The first
 * draw was not corrected: it happened, the press was charged for it, and the delete then handed
 * those priced layers to the **company** at what it paid rather than back to the deal, so the
 * investor's sale completed and his money is his — «استلم الزبون ما استلمش، المطبعة تتحمّل».
 * Posting the restore's draw under the line id would read that settled payment as a figure to
 * correct and reverse it, leaving a deal that shipped 500 kilos, sold all 500, and was paid for
 * 200 of them. So this names the movement and is paid against the movement, exactly as scrap is.
 *
 * **Dispatched after `$order->restore()`, never before.** Every listener runs synchronously
 * inside `RestoreOrder`'s transaction — money and stock land together or neither does — but the
 * order it is about is soft-deleted until that call, and a listener that reads it back through an
 * ordinary scoped query would find nothing and write nothing, silently. That is the same trap
 * §٢٫٢ of Docs/orders/ORDER-DELETE-AND-ARCHIVE.md documents for `ApplyNawrisStatus`.
 *
 * **An event rather than a call**, for the reason {@see OrderProfitFinalised} sets out at length:
 * Orders may not depend on Investment, and a direct call would close a loop the container cannot
 * build. It fires for every re-drawn line, including the many that meet no funded layer at all —
 * whoever listens is expected to find nothing and write nothing, which is the ordinary case.
 */
final readonly class OrderStockRedrawn
{
    use Dispatchable;

    public function __construct(public int $orderId, public int $stockMovementId) {}
}
