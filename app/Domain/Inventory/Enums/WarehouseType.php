<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * What kind of place a warehouse is.
 *
 * Today this only labels and groups a row in a picker — nothing branches on it, and that is
 * deliberate. The moment a rule genuinely differs by type ("the workshop floor cannot receive an
 * arrival"), it belongs here as a predicate next to the cases, the way {@see MovementType} carries
 * `requiresSource()`, rather than as a string compared in three separate actions.
 */
enum WarehouseType: string
{
    /** The main store — where arrivals land and most stock sits. */
    case Main = 'main';

    /** The workshop floor: stock pulled forward for the machines to work through. */
    case Operational = 'operational';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'المخزن الرئيسي',
            self::Operational => 'مخزن التشغيل',
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
