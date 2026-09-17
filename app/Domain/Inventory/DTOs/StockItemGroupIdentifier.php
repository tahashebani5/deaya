<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

/**
 * A reserved primary key together with the group code derived from it.
 *
 * The two travel as one value because the whole point is that they agree: a group whose id is 7
 * always has the code G7. Same shape as {@see StockItemIdentifier}, for the same reason.
 */
final readonly class StockItemGroupIdentifier
{
    public function __construct(
        public int $id,
        public string $code,
    ) {}
}
