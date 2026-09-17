<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Queries;

final readonly class ShippingCompanyFilters
{
    public function __construct(
        /** Matches the company name or its phone number. */
        public ?string $search = null,
        /** null = both. The dispatch picker always asks for true. */
        public ?bool $isActive = null,
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
