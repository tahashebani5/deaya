<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\DTOs\StockItemData;
use App\Domain\Inventory\Models\StockItem;

/**
 * Changes what a shelf is called, how big it is, and whether it is offered.
 *
 * **`unit` is deliberately not here.** Every `WarehouseStock` and `StockBatch` for this item
 * carries a snapshot of it, and rewriting the item's copy while those keep the old one is
 * precisely the drift `ApplyStockChange::guardUnit()` exists to catch. A unit change is
 * {@see SetStockItemUnit}, which moves all of them together under one transaction and one set of
 * locks. `UpdateStockItemRequest` carries no rule for the field, so it cannot arrive here at all.
 *
 * The dimensions *are* editable, and that is not the same risk: they name the shelf, they are
 * snapshotted nowhere, and correcting a typo in a size must not require deleting a pile.
 */
final class UpdateStockItem
{
    public function __invoke(StockItem $item, StockItemData $data): StockItem
    {
        $item->update([
            'name' => $data->name,
            'width_cm' => $data->widthCm,
            'height_cm' => $data->heightCm,
            'description' => $data->description,
            'is_active' => $data->isActive,
            'sort_order' => $data->sortOrder,
        ]);

        return $item;
    }
}
