<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * «نواقص» with nothing recorded as missing.
 *
 * The status exists so somebody can act on it — reprint that size, ring the customer, take it
 * off the invoice — and none of that is possible against an order that only says «something is
 * short». Each line offers its own field and every one of them is optional, because a shortage
 * is usually one size out of several; this is the rule that keeps that leniency honest.
 */
final class ShortageNeedsAQuantity extends DomainException
{
    public static function make(): self
    {
        return new self('حدد الكمية الناقصة من بند واحد على الأقل');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['fields' => [$this->getMessage()]];
    }
}
