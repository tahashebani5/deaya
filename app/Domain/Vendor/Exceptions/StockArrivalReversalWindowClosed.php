<?php

declare(strict_types=1);

namespace App\Domain\Vendor\Exceptions;

use App\Domain\Vendor\Actions\ReverseStockArrival;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Carbon;

/**
 * The 24 hours a receipt may be taken back in have run out.
 *
 * **The one guard a grant can waive**, and the only one that is policy rather than arithmetic:
 * a layer nobody has touched is exactly as safe to withdraw on the third day as on the first, so
 * what this protects is the discipline of owning up to a mistake the same day, not the ledger.
 * Whoever holds `purchase_orders.reverse_receipt_any_time` steps past it with a reason on the
 * record; everybody else is told to raise a stocktake adjustment instead.
 *
 * Measured from when the receipt was *posted*, never from when the purchase order was issued: it
 * is the receipt that moved the stock, and a lorry that took a fortnight to turn up would
 * otherwise arrive with its window already long shut.
 *
 * @see ReverseStockArrival
 */
final class StockArrivalReversalWindowClosed extends DomainException
{
    public static function make(Carbon $closedAt): self
    {
        return new self(
            'انتهت مهلة التراجع عن الاستلام (٢٤ ساعة، انتهت '
            .$closedAt->format('Y-m-d H:i')
            .') — الصواب تسوية جرد، أو تراجع بصلاحية المدير'
        );
    }
}
