<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

final readonly class StockItemGroupFilters
{
    public function __construct(
        /** Matches the material's name — «كيس» finds every family of bags. */
        public ?string $search = null,
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
            isActive: array_key_exists('is_active', $query) && $query['is_active'] !== null && $query['is_active'] !== ''
                ? filter_var($query['is_active'], FILTER_VALIDATE_BOOLEAN)
                : null,
        );
    }
}
