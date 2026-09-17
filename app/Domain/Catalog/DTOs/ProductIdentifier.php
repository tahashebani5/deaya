<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

/**
 * A reserved primary key together with the product code derived from it.
 *
 * The two travel as one value because the whole point is that they agree:
 * a product whose id is 7 always has the code P7.
 */
final readonly class ProductIdentifier
{
    public function __construct(
        public int $id,
        public string $code,
    ) {}
}
