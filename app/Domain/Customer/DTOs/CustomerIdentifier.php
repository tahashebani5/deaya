<?php

declare(strict_types=1);

namespace App\Domain\Customer\DTOs;

/**
 * A reserved primary key together with the customer code derived from it.
 *
 * The two travel as one value because the whole point is that they agree:
 * a customer whose id is 7 always has the code A7.
 */
final readonly class CustomerIdentifier
{
    public function __construct(
        public int $id,
        public string $code,
    ) {}
}
