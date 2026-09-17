<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * Which way a stocktake correction goes.
 *
 * An adjustment names one warehouse and one of these, rather than asking the caller to know
 * that "put it in `to_warehouse_id` to add and `from_warehouse_id` to remove". That mapping is
 * an implementation detail of the ledger's shape, and a storekeeper counting a shelf should not
 * have to hold it in their head — nor should a client developer have to rediscover it from a
 * table description.
 */
enum AdjustmentDirection: string
{
    /** The shelf holds more than the book said — a miscount, or a return that was never recorded. */
    case Increase = 'increase';

    /** The shelf holds less — breakage, waste, or stock that walked. */
    case Decrease = 'decrease';

    public function label(): string
    {
        return match ($this) {
            self::Increase => 'زيادة',
            self::Decrease => 'نقص',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $direction) => $direction->value, self::cases());
    }
}
