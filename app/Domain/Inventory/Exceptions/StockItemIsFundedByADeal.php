<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A shelf carrying somebody else's money does not change the unit it is counted in.
 *
 * Changing it writes the balance off first — a real decreasing adjustment for every warehouse
 * holding the item, so that the quantities are not silently reinterpreted in a new unit. On the
 * company's own stock that is a bookkeeping move. On an investor's, it FIFO-consumes his entire
 * holding and books it as a loss nobody caused.
 */
final class StockItemIsFundedByADeal extends DomainException
{
    public static function make(): self
    {
        return new self('لا يمكن تغيير وحدة قياس مادة يموّلها مستثمر وما زال لها رصيد');
    }
}
