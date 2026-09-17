<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\StockItem;
use Illuminate\Support\Facades\DB;

/**
 * Makes one material's set of product sizes exactly the set it is given.
 *
 * **The link had one writer, and it was the product.** `product_variants.stock_item_id` is set by
 * {@see SyncProductVariants} — explicitly in a product's payload, or resolved from the product's
 * category — so pointing four sizes across three products at one pile meant editing three
 * products, each save rewriting prices, tiers and images that the person doing the pointing never
 * touched. Two people working at once, and one of them loses a price.
 *
 * **A replacement, not an addition.** What is in the list is linked, what is missing is unlinked,
 * and an empty list empties the material on purpose. That is what a multi-select means when it is
 * saved, and it is the only shape in which unticking a box does anything at all.
 *
 * **It lives in Catalog although a material triggers it**, because what it writes is a
 * `ProductVariant` — RULES.md §3: a context owns its own tables and another context reaches them
 * through the Service, never around it. The mirror of {@see SyncProductVariants}, which is
 * Catalog's and asks Inventory which shelf a size belongs on.
 *
 * **One model at a time, never a mass update.** A mass update fires no model events, and a size
 * quietly changing which pile it eats from is the last thing in this system that should leave no
 * trace — `ProductVariant` is `Auditable` precisely so this is answerable later. Same reasoning as
 * the price-tier replacement in {@see SyncProductVariants}.
 *
 * **Moving a size off another material is allowed and is not undone.** Past movements do not
 * follow it: a ledger row is keyed on the material, not on the size that caused it, so what
 * changes is only what this size deducts from next. Whether somebody meant to do that is a
 * question for the screen, which confirms it by name; a caller that says «these four» has said it.
 */
final class PointVariantsAtStockItem
{
    /**
     * @param  list<int>  $variantIds
     */
    public function __invoke(StockItem $item, array $variantIds): StockItem
    {
        DB::transaction(function () use ($item, $variantIds): void {
            // Everything currently on this material that the list left out. An empty list matches
            // every row here, which is what «لا شيء يسحب من هذه المادة» has to mean.
            $item->variants()
                ->whereKeyNot($variantIds)
                ->each(fn (ProductVariant $variant) => $this->point($variant, null));

            // …and everything in the list not already on it. Written as a null check *or* a
            // difference rather than `whereNot(…)`: in SQL `NOT (stock_item_id = 7)` is unknown
            // for a null column, so the sizes that most need linking are exactly the ones a
            // negation would skip.
            ProductVariant::query()
                ->whereKey($variantIds)
                ->where(function ($query) use ($item): void {
                    $query->whereNull('stock_item_id')
                        ->orWhere('stock_item_id', '!=', $item->getKey());
                })
                ->each(fn (ProductVariant $variant) => $this->point($variant, (int) $item->getKey()));
        });

        // Re-read rather than trusted: the caller draws the result, and half of what it draws —
        // which product each size belongs to — was never in the request.
        return $item->load('variants.product')->loadCount('variants');
    }

    /**
     * `forceFill` because `stock_item_id` is not fillable on a variant — a product's payload
     * reaches it through {@see ProductVariantData}, and mass assignment from anywhere else is
     * exactly what that guard exists to stop.
     */
    private function point(ProductVariant $variant, ?int $stockItemId): void
    {
        $variant->forceFill(['stock_item_id' => $stockItemId])->save();
    }
}
