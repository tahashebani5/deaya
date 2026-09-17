<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * Where a cost layer's own cost came from.
 *
 * Not the same question {@see MovementType} answers. A batch relocated by an internal transfer
 * keeps the source type it already had — the transfer is fully recorded as its own
 * `stock_movements` row, so this enum only ever needs to say where the *cost* originated, not how
 * the stock most recently moved.
 */
enum StockBatchSourceType: string
{
    /** Costed from a vendor shipment — `stock_arrival_items.unit_cost`, when it was recorded. */
    case PurchaseArrival = 'purchase_arrival';

    /** Costed from a stocktake correction that found more than the book said. */
    case Adjustment = 'adjustment';

    /**
     * Pre-existing stock with no purchase history to cost it from — backfilled once, at
     * `unit_cost = 0`, the day batch costing went live.
     */
    case OpeningBalance = 'opening_balance';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseArrival => 'توريد',
            self::Adjustment => 'تسوية جرد',
            self::OpeningBalance => 'رصيد افتتاحي',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
