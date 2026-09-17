<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order's money can no longer change.
 *
 * Dispatched on the way into «تم الاستلام» and «تم التسوية» — the two statuses from which the
 * state machine offers no road back and `UpdateOrder` refuses every edit, so `grand_total` and
 * `total_cogs` are both frozen and `grossProfit()` is final.
 *
 * **An event rather than a call**, and RULES §3 says why: Orders may not depend on Investment,
 * Investment already depends on Orders to read a sale's figures, and a direct call would close
 * the loop — literally, at boot: the container would build `ChangeOrderStatus` to build
 * `InvestorService` to build `OrderService` to build `ChangeOrderStatus`. Orders announces a
 * fact about itself; who cares about it is not its business.
 */
final readonly class OrderProfitFinalised
{
    use Dispatchable;

    public function __construct(public int $orderId) {}
}
