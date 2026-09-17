<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

use App\Domain\Inventory\Enums\WarehouseType;

final readonly class WarehouseFilters
{
    public function __construct(
        /** Matches the warehouse name or its location. */
        public ?string $search = null,
        /** null = every kind. */
        public ?WarehouseType $type = null,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromArray(array $query): self
    {
        $search = trim((string) ($query['search'] ?? ''));
        $type = $query['type'] ?? null;

        return new self(
            search: $search !== '' ? $search : null,
            // `tryFrom`, not `from`: this is a query string, not a validated body. A filter
            // naming a type that does not exist should return everything rather than 500.
            type: is_string($type) && $type !== '' ? WarehouseType::tryFrom($type) : null,
        );
    }
}
