<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * One reversal per entry, ever.
 *
 * The partial unique index on `reverses_supply_id` is the guarantee; this is what turns it into a
 * readable 422 instead of a raw 500 — the `PaymentAlreadyReversed` arrangement, checked under the
 * same lock that writes the row.
 */
final class SupplyAlreadyReversed extends DomainException
{
    public static function make(): self
    {
        return new self('عملية التوفير معكوسة أصلاً');
    }
}
