<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

/**
 * A reserved primary key together with the stock item code derived from it.
 *
 * The two travel as one value because the whole point is that they agree: a stock item whose id
 * is 7 always has the code S7. Same shape as `Catalog\DTOs\ProductIdentifier`, for the same
 * reason.
 */
final readonly class StockItemIdentifier
{
    public function __construct(
        public int $id,
        public string $code,
    ) {}
}
