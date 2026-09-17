<?php

declare(strict_types=1);

namespace App\Domain\Investor\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Only an untouched, priced layer can be handed to a deal after the fact.
 *
 * A layer that has been drawn from carries draws already reported against somebody else, and a
 * layer at zero cost would hand the deal a margin it did not earn.
 */
final class BatchIsNotAdoptable extends DomainException
{
    public static function make(string $reason): self
    {
        return new self($reason);
    }
}
