<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\DTOs\StockItemGroupData;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockItemGroup;
use Illuminate\Support\Facades\DB;

/**
 * Renames a material, and every size of it along with it.
 *
 * **The cascade is not a convenience — it is what keeps the schema consistent.** A grouped
 * `StockItem` carries its group's name, and `stock_items_name_size_unique` is what lets
 * `(name, size)` identify exactly one shelf. Renaming the group and leaving the items behind
 * would produce sizes named after a material that no longer exists, and a later group taking the
 * old name would collide with them.
 *
 * One save per item rather than a mass `update()`: a mass update fires no model events, so the
 * rename would leave nothing in the audit trail on the rows that actually changed — the same trap
 * `SyncProductVariants` and `SyncCustomerShops` both document. A material has a handful of sizes.
 *
 * **`default_unit` cascades to nothing**, deliberately. It decides what a size created *later*
 * starts out counted in; an existing item's unit is a snapshot every balance and batch already
 * agrees with, and moving it is {@see SetStockItemUnit}'s job, under locks.
 */
final class UpdateStockItemGroup
{
    public function __invoke(StockItemGroup $group, StockItemGroupData $data): StockItemGroup
    {
        return DB::transaction(function () use ($group, $data): StockItemGroup {
            $renamed = $group->name !== $data->name;

            $group->update([
                'name' => $data->name,
                'default_unit' => $data->defaultUnit ?? $group->default_unit,
                'description' => $data->description,
                'is_active' => $data->isActive,
                'sort_order' => $data->sortOrder,
            ]);

            if ($renamed) {
                $group->items()->each(function (StockItem $item) use ($data): void {
                    $item->update(['name' => $data->name]);
                });
            }

            return $group;
        });
    }
}
