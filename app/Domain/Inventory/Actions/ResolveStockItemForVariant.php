<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Actions\SyncProductVariants;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockItemGroup;

/**
 * Which shelf a product's size draws from, given the material the product is made of.
 *
 * **The whole point of `stock_item_groups`.** A product says «مصنوع من كيس شحن» once; every size
 * it carries finds its own pile here, and كيس شحن سادة 25*35 and كيس شحن مطبوع 25*35 land on the
 * same row without anyone picking it. Before this, each of those links was a separate decision
 * somebody had to get right, and getting it wrong split one heap of bags into two balances.
 *
 * **Creates the size when the material has not reached it.** The alternative is refusing a
 * perfectly good product until somebody goes and creates six stock items by hand — which is
 * exactly the friction the group exists to remove. A shelf minted by a typo holds nothing and is
 * pointed at by nothing once the size is corrected, and `DeleteStockItem` lets it go without
 * argument; a blocked product creation costs more than that.
 *
 * The new size takes the group's `name` — which is what keeps `stock_items_name_size_unique` able
 * to identify one shelf — and its `default_unit`. It never takes the *product's* `pricing_unit`:
 * a thing bought in by weight and sold by the piece needs those two to differ, and the shelf's
 * side of that pair belongs to the material.
 *
 * **Never overrules an explicit choice.** The caller decides precedence, not this action:
 * {@see SyncProductVariants} only reaches here when a variant arrived
 * without a `stock_item_id` of its own. A 25*35 bag deliberately cut from a wider sheet keeps
 * saying so.
 */
final class ResolveStockItemForVariant
{
    public function __invoke(StockItemGroup $group, ?int $widthCm, ?int $heightCm): StockItem
    {
        $existing = $group->itemForSize($widthCm, $heightCm);

        if ($existing !== null) {
            return $existing;
        }

        // Assigned rather than mass-assigned: `name` is not fillable on a grouped item — it is
        // the group's to say — and `code` is server-allocated. See StockItem's docblock.
        $item = new StockItem;
        $item->stock_item_group_id = $group->getKey();
        $item->name = $group->name;
        $item->width_cm = $widthCm;
        $item->height_cm = $heightCm;
        $item->unit = $group->default_unit;
        $item->is_active = true;
        $item->sort_order = 0;
        $item->save();

        return $item;
    }
}
