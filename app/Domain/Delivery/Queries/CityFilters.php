<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Queries;

final readonly class CityFilters
{
    public function __construct(
        /** Matches the city name or its شركة درب branch. */
        public ?string $search = null,
        /** null = both. */
        public ?bool $isRegionRequired = null,
        /** false finds the cities still waiting for a rate to be agreed. */
        public ?bool $hasPrice = null,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromArray(array $query): self
    {
        $search = trim((string) ($query['search'] ?? ''));

        return new self(
            search: $search !== '' ? $search : null,
            isRegionRequired: self::boolOrNull($query, 'is_region_required'),
            hasPrice: self::boolOrNull($query, 'has_price'),
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
