<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

/**
 * What one warehouse holds, in five numbers.
 *
 * **`lowStockCount` and `outOfStockCount` overlap on purpose.** A shelf at zero that somebody
 * asked to be warned about is both, and each of the two counts is defined to match *exactly* the
 * filter the button beside it opens — so a button never promises a number the list then
 * contradicts. `healthyCount` is therefore what is left over rather than a subtraction the
 * reader has to trust, which is also what keeps the three segments of the bar exclusive.
 */
final readonly class StockSummary
{
    public function __construct(
        /** How many sizes have ever been on these shelves — a line at zero is still a line. */
        public int $totalLines,

        /** Every balance added together, as a string for the same reason a balance is one. */
        public string $totalQuantity,

        /** Has a threshold and has fallen to it. Matches `low_stock=true`. */
        public int $lowStockCount,

        /** Nothing left. Matches `in_stock=false`. */
        public int $outOfStockCount,

        /** Neither low nor empty. */
        public int $healthyCount,
    ) {}
}
