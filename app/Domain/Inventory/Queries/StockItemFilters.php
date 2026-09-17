<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

final readonly class StockItemFilters
{
    public function __construct(
        /** Matches the material's name — «كيس شحن» finds every size of it. */
        public ?string $search = null,
        public ?bool $isActive = null,
        /**
         * The two halves of a size, filtered independently so a picker can ask for everything
         * 25 wide as readily as for one exact shelf.
         *
         * What the stock-item chooser on a product size uses: given a variant of 25*35, offer the
         * shelves that are 25*35 first. Deliberately a filter and not a constraint — a 25*35 bag
         * can legitimately be cut from a wider sheet; see the migration's note.
         */
        public ?int $widthCm = null,
        public ?int $heightCm = null,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromArray(array $query): self
    {
        $search = trim((string) ($query['search'] ?? ''));

        return new self(
            search: $search !== '' ? $search : null,
            isActive: self::boolOrNull($query, 'is_active'),
            widthCm: self::intOrNull($query, 'width_cm'),
            heightCm: self::intOrNull($query, 'height_cm'),
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

    /**
     * @param  array<string, mixed>  $query
     */
    private static function intOrNull(array $query, string $key): ?int
    {
        return array_key_exists($key, $query) && $query[$key] !== null && $query[$key] !== ''
            ? (int) $query[$key]
            : null;
    }
}
