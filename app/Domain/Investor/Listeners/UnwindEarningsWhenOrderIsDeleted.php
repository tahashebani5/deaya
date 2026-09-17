<?php

declare(strict_types=1);

namespace App\Domain\Investor\Listeners;

use App\Domain\Investor\InvestorService;
use App\Domain\Order\Events\OrderProfitUnwound;

/**
 * Takes an archived order's profit back out of the deals that financed its stock.
 *
 * **Synchronous, never queued** — the same bargain {@see PostEarningsWhenOrderIsFinalised} makes,
 * for the same reason. It runs inside the transaction `DeleteOrder` opened, so either the order
 * leaves the books and the earnings leave the wallets with it, or neither happens. Queued, a
 * failed job would leave an archived order whose investors are still holding its profit, and
 * nothing on any screen would say so.
 */
final class UnwindEarningsWhenOrderIsDeleted
{
    public function __construct(private readonly InvestorService $investors) {}

    public function handle(OrderProfitUnwound $event): void
    {
        $this->investors->unwindEarningsForOrder($event->orderId);
    }
}
