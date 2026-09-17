<?php

declare(strict_types=1);

namespace App\Domain\Vendor\Exceptions;

use App\Domain\Vendor\Actions\ReverseStockArrival;
use App\Support\Exceptions\DomainException;

/**
 * A receipt is undone once. A second attempt is refused rather than treated as a no-op: the
 * caller believes there is stock on the shelf to take back, and there is not.
 *
 * The readable half of a rule the database also holds: the partial UNIQUE on
 * `stock_movements.reverses_movement_id` makes a second withdrawal against the same arrival
 * movement an error even if this guard were ever bypassed. This one exists so the ordinary case
 * answers in Arabic with a 422 instead of a constraint violation.
 *
 * @see ReverseStockArrival
 */
final class StockArrivalAlreadyReversed extends DomainException
{
    public static function make(int $arrivalId): self
    {
        return new self("تم التراجع عن الشحنة رقم {$arrivalId} من قبل");
    }
}
