<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * The entry named in the path is on somebody else's shortage.
 *
 * A 422 rather than a 404: the row exists and the reader may well be allowed to see it, so
 * pretending it is missing would send them hunting for a typo in the wrong half of the URL — the
 * `PurchaseOrderItemDoesNotBelongToOrder` shape.
 */
final class SupplyDoesNotBelongToShortage extends DomainException
{
    public static function make(): self
    {
        return new self('عملية التوفير ليست على هذا النقص');
    }
}
