<?php

declare(strict_types=1);

namespace App\Domain\Investor\DTOs;

/** One shelf a deal funds, and what was expected of it the day it was struck. */
final readonly class DealItemData
{
    public function __construct(
        public int $stockItemId,
        public ?string $quantityExpected = null,
        public ?string $expectedUnitCost = null,
        public ?string $expectedUnitPrice = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            stockItemId: (int) $row['stock_item_id'],
            quantityExpected: self::decimalOrNull($row['quantity_expected'] ?? null, 3),
            expectedUnitCost: self::decimalOrNull($row['expected_unit_cost'] ?? null, 3),
            expectedUnitPrice: self::decimalOrNull($row['expected_unit_price'] ?? null, 3),
            notes: isset($row['notes']) && trim((string) $row['notes']) !== ''
                ? trim((string) $row['notes'])
                : null,
        );
    }

    private static function decimalOrNull(mixed $value, int $scale): ?string
    {
        return $value === null || $value === ''
            ? null
            : number_format((float) $value, $scale, '.', '');
    }
}
