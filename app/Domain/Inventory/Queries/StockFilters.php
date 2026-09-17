<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

final readonly class StockFilters
{
    public function __construct(
        /** One size's balance in this warehouse. */
        public ?int $stockItemId = null,
        /** true = only the lines that have fallen to their threshold. false = only the ones that have not. */
        public ?bool $lowStock = null,
        /** false finds the sizes that have been here and are now used up. */
        public ?bool $inStock = null,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromArray(array $query): self
    {
        $stockItemId = $query['stock_item_id'] ?? null;

        return new self(
            stockItemId: $stockItemId !== null && $stockItemId !== '' ? (int) $stockItemId : null,
            lowStock: self::boolOrNull($query, 'low_stock'),
            inStock: self::boolOrNull($query, 'in_stock'),
        );
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
