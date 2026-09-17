<?php

declare(strict_types=1);

namespace App\Domain\Investor\Listeners;

use App\Domain\Investor\InvestorService;
use App\Domain\Order\Events\OrderStockRedrawn;

/**
 * Pays for the layers a restored order's fresh draw took off the shelf.
 *
 * «أي حاجة تطلع من المخزون الكيلو يمشي بسعر السادة بالوزن» — the owner, 2026-09-06. The bags a
 * restore draws left the warehouse like any others, and whoever financed them is owed the same
 * margin as on the first draw.
 *
 * **Paid against the movement, not the order's lines**, and {@see OrderStockRedrawn} carries the
 * reasoning: the line's earlier payment settled a sale that really completed — the delete gave
 * those goods to the company, not back to the deal — so this is an extra purchase beside it, not
 * a correction of it. The same arrangement {@see PostPurchaseWhenScrapIsDrawn} has, for the same
 * reason.
 *
 * **Synchronous, never queued**, for the reason its three siblings give: it runs inside the
 * transaction `RestoreOrder` opened, so the goods leaving and the payment for them either both
 * stand or neither does.
 */
final class PostPurchaseWhenStockIsRedrawn
{
    public function __construct(private readonly InvestorService $investors) {}

    public function handle(OrderStockRedrawn $event): void
    {
        $this->investors->postStockPurchaseForMovement($event->stockMovementId);
    }
}
