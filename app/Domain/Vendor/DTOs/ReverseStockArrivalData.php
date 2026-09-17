<?php

declare(strict_types=1);

namespace App\Domain\Vendor\DTOs;

use App\Domain\Vendor\Actions\ReverseStockArrival;

/**
 * Undoing a receipt: who is undoing it, why, and whether they may step past the window.
 *
 * `reversedBy` is passed in rather than read from a payload, the same rule
 * {@see StockArrivalData::$receivedBy} follows — so a correction can never be attributed to the
 * colleague who made the mistake.
 *
 * `ignoreWindow` is likewise decided at the boundary and never sent by a client: the controller
 * reads it off the caller's own grants. The domain keeps the policy — {@see ReverseStockArrival}
 * is what knows the window exists and how long it is — and is merely told who is asking.
 */
final readonly class ReverseStockArrivalData
{
    public function __construct(
        /** Why this receipt was wrong. Required — an inventory correction nobody has to account
         * for is the shape this whole feature exists to prevent. */
        public string $reason,
        public int $reversedBy,
        /**
         * Whether the 24-hour window may be stepped past, because the caller holds
         * `purchase_orders.reverse_receipt_any_time`.
         *
         * **It waives the clock and nothing else.** Every other guard — the stock never drawn on,
         * the layers never repriced, the receipt not already undone — applies to a manager
         * exactly as it applies to a storekeeper, because those are arithmetic and this is
         * policy.
         */
        public bool $ignoreWindow = false,
    ) {}
}
