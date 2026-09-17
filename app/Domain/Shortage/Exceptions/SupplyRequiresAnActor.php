<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A stock movement is signed, always.
 *
 * `stock_movements.employee_id` is NOT NULL and assigned from the authenticated user rather than
 * from a payload, so a supply that posts an arrival cannot be recorded by nobody — the same rule
 * `FulfillmentRequiresAnActor` and `ScrapRequiresAnActor` state for the two other domain-built
 * movements.
 *
 * A supply that moves no stock — a free-text shortage — is exempt, and a seeder or console
 * command may write one unsigned.
 */
final class SupplyRequiresAnActor extends DomainException
{
    public static function make(): self
    {
        return new self('تسجيل إدخال مخزني يحتاج موظفاً موقِّعاً');
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
