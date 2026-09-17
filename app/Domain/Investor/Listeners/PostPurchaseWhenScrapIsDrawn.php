<?php

declare(strict_types=1);

namespace App\Domain\Investor\Listeners;

use App\Domain\Investor\InvestorService;
use App\Domain\Order\Events\OrderScrapDrawn;

/**
 * Pays for the bags a spoiled run took off the shelf.
 *
 * «هي من لما تكون جاهزة وبينخصم من المخزون خلاص اعطيه حقاته» — the owner, 2026-09-06. A kilo
 * that leaves is sold; what became of it is the press's affair.
 *
 * **Synchronous, never queued**, for the reason its two siblings give: it runs inside the
 * transaction `RecordScrapLoss` opened, so the loss and the payment for it either both stand or
 * neither does.
 */
final class PostPurchaseWhenScrapIsDrawn
{
    public function __construct(private readonly InvestorService $investors) {}

    public function handle(OrderScrapDrawn $event): void
    {
        $this->investors->postStockPurchaseForMovement($event->stockMovementId);
    }
}
