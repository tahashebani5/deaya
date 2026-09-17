<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Enums\StockAdjustmentReason;
use Carbon\CarbonImmutable;

/**
 * What a ledger screen is asking for.
 *
 * `warehouseId` matches either end on purpose. "Show me everything that happened at the main
 * store" plainly means arrivals *and* despatches; making the caller ask twice and merge the two
 * pages would be an implementation detail of the table's shape leaking into the API.
 */
final readonly class MovementFilters
{
    public function __construct(
        public ?int $warehouseId = null,
        public ?int $stockItemId = null,
        public ?MovementType $movementType = null,
        /** «أرِني كل الهالك هذا الشهر» — the question free-text notes could never answer. */
        public ?StockAdjustmentReason $adjustmentReason = null,
        public ?int $employeeId = null,
        /** The order a fulfillment belongs to, once Orders lands. */
        public ?int $referenceId = null,
        /** Inclusive: the whole of this day counts. */
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromArray(array $query): self
    {
        $type = $query['movement_type'] ?? null;
        $reason = $query['adjustment_reason'] ?? null;

        return new self(
            warehouseId: self::intOrNull($query['warehouse_id'] ?? null),
            stockItemId: self::intOrNull($query['stock_item_id'] ?? null),
            // `tryFrom`: an unknown type in a query string returns everything rather than 500.
            movementType: is_string($type) && $type !== '' ? MovementType::tryFrom($type) : null,
            adjustmentReason: is_string($reason) && $reason !== ''
                ? StockAdjustmentReason::tryFrom($reason)
                : null,
            employeeId: self::intOrNull($query['employee_id'] ?? null),
            referenceId: self::intOrNull($query['reference_id'] ?? null),
            from: self::date($query['from'] ?? null)?->startOfDay(),
            // End of day, not midnight — same reasoning as ActivityFilters: `to=2026-08-03`
            // plainly means "including today", and a bare date parsed as 00:00 would exclude
            // every movement the day actually contains.
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
