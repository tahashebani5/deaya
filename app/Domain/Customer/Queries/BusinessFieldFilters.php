<?php

declare(strict_types=1);

namespace App\Domain\Customer\Queries;

final readonly class BusinessFieldFilters
{
    public function __construct(
        /** Matches the field's name. */
        public ?string $search = null,
        /** null = both. `true` is what a picker asks for; the management screen asks for both. */
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
