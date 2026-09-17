<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order's money has left the books entirely — it was archived, not merely closed.
 *
 * **The exact counterpart of {@see OrderProfitFinalised}, and it exists because that one cannot
 * be re-dispatched to mean this.** `PostDealEarningsForOrder` starts by asking
 * `OrderService::profitAttributionFor()`, whose query is soft-delete scoped: on a deleted order
 * it answers `null` and the action returns early having reversed nothing at all. So the standing
 * `profit`/`loss` rows would sit in the investors' wallets for ever, paying men for a sale that
 * no report still counts — see §٢٫١ of Docs/orders/ORDER-DELETE-AND-ARCHIVE.md, which chose to
 * reverse money rather than refuse the delete.
 *
 * **An event rather than a call**, for the reason `OrderProfitFinalised` sets out at length:
 * Orders may not depend on Investment, and a direct call would be a loop the container cannot
 * build — `DeleteOrder` → `InvestorService` → `OrderService` → `DeleteOrder`. Orders announces a
 * fact about itself; who is out of pocket for it is not its business.
 *
 * Dispatched **after** the row is archived and **inside** the delete's transaction, so the order
 * leaving the books and the earnings leaving the wallets are one atomic fact.
 */
final readonly class OrderProfitUnwound
{
    use Dispatchable;

    public function __construct(public int $orderId) {}
}
