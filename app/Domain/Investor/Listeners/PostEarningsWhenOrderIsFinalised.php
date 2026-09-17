<?php

declare(strict_types=1);

namespace App\Domain\Investor\Listeners;

use App\Domain\Investor\InvestorService;
use App\Domain\Order\Events\OrderProfitFinalised;

/**
 * Splits a finalised order's profit among the deals that financed its stock.
 *
 * **Synchronous, never queued.** It runs inside the transaction `ChangeOrderStatus` opened, so
 * either the status moves and the money is booked or neither happens. Queued, a failed job would
 * leave a delivered order whose investors were never paid, and nothing on any screen would say
 * so.
 */
final class PostEarningsWhenOrderIsFinalised
{
    public function __construct(private readonly InvestorService $investors) {}

    public function handle(OrderProfitFinalised $event): void
    {
        $this->investors->postEarningsForOrder($event->orderId);
    }
}
