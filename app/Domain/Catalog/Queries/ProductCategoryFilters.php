<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Queries;

final readonly class ProductCategoryFilters
{
    public function __construct(
        /** Matches the category's name. */
        public ?string $search = null,
        /** null = both. `true` is what a picker asks for; the management screen asks for both. */
        public ?bool $isActive = null,
        /**
         * Only the headings a product may actually be filed under.
         *
         * What the product form's picker asks for: a heading with children is a heading, not a
         * slot. The management screen leaves it off and sees the whole list.
         */
        public bool $leafOnly = false,
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
            leafOnly: filter_var($query['leaf_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }
}
