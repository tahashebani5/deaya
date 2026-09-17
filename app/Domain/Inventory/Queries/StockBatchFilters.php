<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

final readonly class StockBatchFilters
{
    public function __construct(
        public ?int $warehouseId = null,
        public ?int $stockItemId = null,
        /**
         * true = only the layers nobody has priced, which is the whole point of this filter.
         * FIFO draws the oldest first and the opening-balance backfill stamped 1970 on every
         * one of them, so each of these has a deadline: it is the next thing off its shelf.
         */
        public ?bool $uncosted = null,
        /** false = the layers that are used up, kept for the record but not repriceable. */
        public ?bool $remaining = null,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromArray(array $query): self
    {
        return new self(
            warehouseId: self::intOrNull($query, 'warehouse_id'),
            stockItemId: self::intOrNull($query, 'stock_item_id'),
            uncosted: self::boolOrNull($query, 'uncosted'),
            remaining: self::boolOrNull($query, 'remaining'),
        );
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function intOrNull(array $query, string $key): ?int
    {
        $value = $query[$key] ?? null;

        return $value !== null && $value !== '' ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function boolOrNull(array $query, string $key): ?bool
    {
        return array_key_exists($key, $query) && $query[$key] !== null && $query[$key] !== ''
            ? filter_var($query[$key], FILTER_VALIDATE_BOOLEAN)
            : null;
    }
}
