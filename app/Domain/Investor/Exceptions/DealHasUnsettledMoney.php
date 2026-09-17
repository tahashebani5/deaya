<?php

declare(strict_types=1);

namespace App\Domain\Investor\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Closing is the last act, so nothing may be left owing when it happens.
 */
final class DealHasUnsettledMoney extends DomainException
{
    public static function make(string $code): self
    {
        return new self("لا يمكن إغلاق الصفقة {$code} قبل تسوية رأس المال والأرباح");
    }
}
