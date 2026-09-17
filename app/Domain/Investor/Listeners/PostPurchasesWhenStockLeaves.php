<?php

declare(strict_types=1);

namespace App\Domain\Investor\Listeners;

use App\Domain\Investor\InvestorService;
use App\Domain\Order\Events\OrderStockDrawn;

/**
 * Settles سعر السادة the moment an order's printed lines take their material off the shelf.
 *
 * **Synchronous, never queued**, for the reason its sibling
 * {@see PostEarningsWhenOrderIsFinalised} gives: it runs inside the transaction
 * `ChangeOrderStatus` opened, so either the stock moves and the seller is paid or neither
 * happens. Queued, a failed job would leave an investor's goods in the press and nothing in his
 * ledger, with no screen anywhere saying so.
 */
final class PostPurchasesWhenStockLeaves
{
    public function __construct(private readonly InvestorService $investors) {}

    public function handle(OrderStockDrawn $event): void
    {
        $this->investors->postStockPurchasesForOrder($event->orderId);
    }
}
