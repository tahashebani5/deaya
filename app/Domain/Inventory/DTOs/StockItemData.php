<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\Actions\CreateStockItem;
use App\Domain\Inventory\Actions\SetStockItemUnit;

/**
 * Everything a caller may say about a shelf.
 *
 * `unit` is nullable here rather than required, because the two callers differ: creating a stock
 * item must state one, updating one may not change it — the unit of a pile that already has
 * batches and balances snapshotted against it moves only through
 * {@see SetStockItemUnit}, which does it under locks. `UpdateStockItem`
 * therefore ignores this field entirely, and `UpdateStockItemRequest` carries no rule for it.
 */
final readonly class StockItemData
{
    public function __construct(
        /**
         * Ignored when `stockItemGroupId` is set — a grouped size is called what its material is
         * called, and {@see CreateStockItem} takes it from there.
         */
        public string $name,
        public ?PricingUnit $unit = null,
        /**
         * The material this is a size of. Null for a standalone shelf.
         *
         * Set at creation only. Re-filing a size under another material would rename it, and a
         * rename is the one edit that can collide with a shelf that already exists — so
         * `UpdateStockItemRequest` carries no rule for it.
         */
        public ?int $stockItemGroupId = null,
        /** Null for a stock item that is not a size — a roll, an ink, anything without dimensions. */
        public ?int $widthCm = null,
        public ?int $heightCm = null,
        public ?string $description = null,
        /** A shelf is usable the moment it exists; hiding one is a later, deliberate act. */
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
            name: trim((string) ($validated['name'] ?? '')),
            unit: isset($validated['unit']) && $validated['unit'] !== ''
                ? PricingUnit::from((string) $validated['unit'])
                : null,
            stockItemGroupId: isset($validated['stock_item_group_id']) && $validated['stock_item_group_id'] !== ''
                ? (int) $validated['stock_item_group_id']
                : null,
            widthCm: isset($validated['width_cm']) ? (int) $validated['width_cm'] : null,
            heightCm: isset($validated['height_cm']) ? (int) $validated['height_cm'] : null,
            description: $description !== '' ? $description : null,
            isActive: (bool) ($validated['is_active'] ?? true),
            sortOrder: (int) ($validated['sort_order'] ?? 0),
        );
    }
}
