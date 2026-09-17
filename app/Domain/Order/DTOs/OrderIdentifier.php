<?php

declare(strict_types=1);

namespace App\Domain\Order\DTOs;

/**
 * A reserved primary key together with the order number derived from it.
 *
 * The two travel as one value because the whole point is that they agree: an order whose id is
 * 7 always shows the number 7.
 */
final readonly class OrderIdentifier
{
    public function __construct(
        public int $id,
        public string $code,
    ) {}
}
