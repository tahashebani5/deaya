<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Listeners;

use App\Domain\Order\Events\OrderStockRedrawn;
use App\Domain\Shortage\Actions\SyncShortagesFromOrder;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Brings an order's shortages back when the order comes back.
 *
 * **The reconciliation does all of it, unchanged.** A restored order still carries whatever
 * `shortage_quantity` its lines had when it was archived — `DeleteOrder` does not clear them —
 * so running the sync produces exactly the rows that should exist: the ones that were
 * soft-deleted for having nothing against them are recreated, and the ones that were kept and
 * marked «غير متوفر» have their requirement restated and are reopened by the arithmetic if it
 * grew. Nothing here needs to know which of those happened.
 *
 * **The quantity comes back; the price does not have to.** `RestoreOrder` re-draws stock against
 * today's cost layers, so the order returns costed differently from how it left — a point
 * ORDER-DELETE-AND-ARCHIVE §١ makes at length. A shortage quantity is a physical fact about sacks
 * that are not on a shelf, carries no cost layer, and so simply returns as it was.
 *
 * `OrderStockRedrawn` is the restore's own announcement, which is what keeps this out of
 * `Domain/Order` — the road `PostPurchaseWhenStockIsRedrawn` already takes for the same event.
 */
final class ReopenShortagesWhenOrderIsRestored implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly SyncShortagesFromOrder $sync) {}

    public function handle(OrderStockRedrawn $event): void
    {
        // No actor: a restore is one person's action on an order, not on any one shortage, and
        // stamping them as the creator of rows they never saw would put their name on work the
        // board is about to hand to somebody else.
        ($this->sync)($event->orderId);
    }
}
