<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

use App\Domain\Catalog\Enums\PricingUnit;

/**
 * Everything a caller may say about a material.
 *
 * `defaultUnit` is nullable for the reason `StockItemData::$unit` is: creating a group must state
 * one, updating it may leave it alone. Unlike a stock item's own `unit`, changing this is
 * harmless — it decides what a *future* size is created counted in, and touches nothing that
 * already exists.
 */
final readonly class StockItemGroupData
{
    public function __construct(
        public string $name,
        public ?PricingUnit $defaultUnit = null,
        public ?string $description = null,
        /** A material is usable the moment it exists; hiding one is a later, deliberate act. */
        public bool $isActive = true,
        /** Where it sits in a picker. Equal values fall back to the name. */
        public int $sortOrder = 0,
    ) {}

    /**
     * Built from already-validated request data — the one place an array crosses into the domain.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        // Trimmed here rather than at the boundary: a name with a trailing space is a second
        // «كيس شحن» as far as the unique index is concerned, and nobody would see why.
        $description = trim((string) ($validated['description'] ?? ''));

        return new self(
            name: trim((string) $validated['name']),
            defaultUnit: isset($validated['default_unit'])
                ? PricingUnit::from((string) $validated['default_unit'])
                : null,
            description: $description !== '' ? $description : null,
            isActive: (bool) ($validated['is_active'] ?? true),
            sortOrder: (int) ($validated['sort_order'] ?? 0),
        );
    }
}
