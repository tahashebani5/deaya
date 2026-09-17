<?php

declare(strict_types=1);

namespace App\Domain\Vendor\Queries;

use Carbon\CarbonImmutable;

final readonly class StockArrivalFilters
{
    public function __construct(
        public ?int $vendorId = null,
        public ?int $warehouseId = null,
        /** Inclusive: the whole of this day counts. */
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromArray(array $query): self
    {
        return new self(
            vendorId: self::intOrNull($query['vendor_id'] ?? null),
            warehouseId: self::intOrNull($query['warehouse_id'] ?? null),
            from: self::date($query['from'] ?? null)?->startOfDay(),
            // End of day, not midnight — `to=2026-08-03` plainly means "including today", the
            // same reasoning MovementFilters carries.
            to: self::date($query['to'] ?? null)?->endOfDay(),
        );
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
