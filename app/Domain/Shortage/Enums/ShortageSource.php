<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Enums;

use App\Domain\Shortage\Actions\SyncShortagesFromOrder;
use App\Domain\Shortage\Models\Shortage;

/**
 * Where a shortage came from, and therefore who owns its numbers.
 *
 * Not a label. {@see FromOrder} means an order line is the authority on how much is missing —
 * `required_quantity` is reconciled from it on every sync (SHORTAGES-DESIGN §٣) and the fields
 * that describe *what* is short are copied from the line, so letting a clerk edit them here would
 * put two different names on one sack. {@see Manual} means nothing upstream exists and the
 * employee's own entry is the only record there is.
 *
 * Stored rather than derived from `order_item_id` being null, because the two come apart: a line
 * deleted off its order leaves a shortage that is still order-born, still carries its money, and
 * still must not be edited into something else — see {@see Shortage::isEditable()}.
 */
enum ShortageSource: string
{
    /** Somebody typed it. The product link is optional — see `CreateShortage`. */
    case Manual = 'manual';

    /** Generated from an order line entering «نواقص» — see {@see SyncShortagesFromOrder}. */
    case FromOrder = 'order';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'يدوي',
            self::FromOrder => 'من طلبية',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $source) => $source->value, self::cases());
    }
}
