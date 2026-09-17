<?php

declare(strict_types=1);

namespace App\Domain\Investor\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A deal is not finished while its goods are still on a shelf.
 *
 * Closing returns capital and releases profit for withdrawal; doing that while FIFO can still
 * draw from the deal would pay out money it has not finished earning — or losing.
 */
final class DealStillHoldsStock extends DomainException
{
    public static function make(string $code): self
    {
        return new self("لا يمكن إغلاق الصفقة {$code} وما زال لها مخزون على الرفّ");
    }
}
