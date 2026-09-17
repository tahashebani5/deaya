<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\DTOs\StockItemGroupData;
use App\Domain\Inventory\Models\StockItemGroup;

/**
 * Opens a new material.
 *
 * It starts with no sizes at all, and that is the normal case: the sizes arrive as products name
 * them, created on demand by {@see ResolveStockItemForVariant}. Nobody has to enumerate a
 * material's sizes up front to start using it.
 *
 * `default_unit` falls back to `Piece` only for a caller that skipped the boundary — the store
 * request requires the field.
 */
final class CreateStockItemGroup
{
    public function __invoke(StockItemGroupData $data): StockItemGroup
    {
        return StockItemGroup::query()->create([
            'name' => $data->name,
            'default_unit' => $data->defaultUnit ?? PricingUnit::Piece,
            'description' => $data->description,
            'is_active' => $data->isActive,
            'sort_order' => $data->sortOrder,
        ]);
    }
}
