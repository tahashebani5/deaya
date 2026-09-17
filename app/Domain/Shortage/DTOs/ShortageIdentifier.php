<?php

declare(strict_types=1);

namespace App\Domain\Shortage\DTOs;

/**
 * A reserved primary key together with the shortage code derived from it.
 *
 * The two travel as one value because the whole point is that they agree: a shortage whose id is
 * 7 always shows N7. The `OrderIdentifier` shape.
 */
final readonly class ShortageIdentifier
{
    public function __construct(
        public int $id,
        public string $code,
    ) {}
}
