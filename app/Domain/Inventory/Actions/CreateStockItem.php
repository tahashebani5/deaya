<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\DTOs\StockItemData;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Queries\FindStockItemGroup;

/**
 * Opens a new shelf.
 *
 * **Under a material, the material decides two of these fields.** `name` is the group's — every
 * size of «كيس شحن» is called «كيس شحن», which is what keeps `stock_items_name_size_unique` able
 * to identify one shelf — and `unit` falls back to the group's `default_unit` when the caller did
 * not state one. Both are why `StoreStockItemRequest` stops requiring `name` and `unit` the moment
 * a `stock_item_group_id` is supplied.
 *
 * `unit` is the one field this action reaches that {@see UpdateStockItem} does not: a pile has to
 * be countable or weighable from the moment it exists, and afterwards changing it means restamping
 * every balance and batch snapshotted against it — {@see SetStockItemUnit}'s job, under locks.
 *
 * The group is likewise set here and never by an update, for the same reason in a different key:
 * re-filing a size under another material would rename it, and a rename is the one edit that can
 * collide with a shelf that already exists. Nothing in the ordinary flow needs it — a product
 * naming its material creates the sizes under that material to begin with.
 */
final class CreateStockItem
{
    public function __construct(private readonly FindStockItemGroup $findGroup) {}

    public function __invoke(StockItemData $data): StockItem
    {
        $group = $data->stockItemGroupId !== null
            ? ($this->findGroup)($data->stockItemGroupId)
            : null;

        $item = new StockItem([
            'name' => $group?->name ?? $data->name,
            'width_cm' => $data->widthCm,
            'height_cm' => $data->heightCm,
            'unit' => $data->unit ?? $group?->default_unit ?? PricingUnit::Piece,
            'description' => $data->description,
            'is_active' => $data->isActive,
            'sort_order' => $data->sortOrder,
        ]);

        // Assigned rather than mass-assigned: which material a shelf belongs to is not a field a
        // later `PUT` may move, so it is deliberately absent from the fillable list.
        $item->stock_item_group_id = $group?->getKey();
        $item->save();

        return $item;
    }
}
