<?php

declare(strict_types=1);

namespace App\Domain\Investor\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * More money into a deal whose ownership is already frozen.
 *
 * A deal born from a purchase order fixed, at funding, what fraction of the goods the partners'
 * money bought. Capital added afterwards buys nothing — there is no more stock for it to own and
 * the percent it would need to move is frozen — so it would sit in the deal, come back at close,
 * and earn its owner not one dinar more while the deal screen showed his capital and his
 * ownership disagreeing. Refused at the door instead: more money is a new deal.
 */
final class DealTakesNoMoreCapital extends DomainException
{
    public static function make(string $code): self
    {
        return new self("الصفقة {$code} مموَّلة من أمر شراء وشروطها مقفلة؛ المال الإضافي صفقة جديدة");
    }
}
