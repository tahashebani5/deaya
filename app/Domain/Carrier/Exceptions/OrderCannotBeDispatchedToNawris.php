<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * The order is not something a carrier can take.
 *
 * Refused before any HTTP call, per the contract's "precondition to enforce yourself" column. An
 * «استلام مكتب» order never leaves the building, so handing one to a courier would be inventing a
 * journey it is not making.
 */
final class OrderCannotBeDispatchedToNawris extends DomainException
{
    public static function notADelivery(string $code): self
    {
        return new self("الطلبية {$code} استلام مكتب، ولا تُسلَّم لشركة توصيل");
    }

    public static function notOnItsWay(string $code, string $status): self
    {
        return new self("الطلبية {$code} في «{$status}»، ولا تُسلَّم لشركة توصيل قبل «جاهزة»");
    }
}
