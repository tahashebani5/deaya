<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\DTOs\ProductVariantData;
use App\Domain\Catalog\Exceptions\DuplicateVariantLabel;
use App\Domain\Catalog\Exceptions\VariantDoesNotBelongToProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Actions\ResolveStockItemForVariant;
use App\Domain\Inventory\InventoryService;

/**
 * Makes a product's variants — and each variant's price tiers — match the given set exactly.
 *
 * Variants carrying an `id` are updated in place, ones without are created, and any left out is
 * removed. A variant's tiers are always replaced wholesale rather than diffed: a price list is
 * read as a unit, and rewriting it is both simpler and safer than trying to match rows whose
 * only identity is a threshold that may itself be the thing being edited.
 */
final class SyncProductVariants
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * @param  list<ProductVariantData>  $variants
     */
    public function __invoke(Product $product, array $variants): void
    {
        $keptIds = [];

        foreach ($variants as $variantData) {
            $variant = $this->upsertVariant($product, $variantData);
            $keptIds[] = $variant->getKey();

            // Replace the whole price list for this variant.
            //
            // One model at a time, not `->delete()` on the relation. A mass delete fires no
            // model events, which since prices became soft-deleted and audited would mean the
            // single most consequential change in the catalogue — a price disappearing — left
            // no entry saying who did it.
            $variant->priceTiers()->each(fn (ProductPriceTier $tier) => $tier->delete());

            foreach ($variantData->priceTiers as $tier) {
                $variant->priceTiers()->create([
                    'min_quantity' => $tier->minQuantity,
                    'unit_price' => $tier->unitPrice,
                ]);
            }
        }

        // One model at a time, not a bulk delete on the relation: a mass delete fires no model
        // events, so the removed sizes would leave nothing behind in the audit trail — and
        // their price tiers would not go with them, because the cascade is a model event now
        // (see CascadesSoftDeletes) rather than the foreign key it used to be.
        $product->variants()
            ->whereKeyNot($keptIds)
            ->each(fn (ProductVariant $variant) => $variant->delete());
    }

    /**
     * Which shelf this size draws from, in the order the three answers are allowed to win.
     *
     *   1. **An explicit `stock_item_id` in the payload.** Always beats the material, so a 25*35
     *      bag deliberately cut from a wider sheet keeps saying so.
     *   2. **The product's material**, if it has one — the size is matched, or created, under it
     *      by {@see ResolveStockItemForVariant}. This is the whole
     *      point of `products.stock_item_group_id`: say the material once, and every size finds
     *      its own pile.
     *   3. **Null** — a quote-only size, or a product whose material nobody has named. Every path
     *      that moves stock refuses such a size by name rather than dereferencing null.
     *
     * Asked of `InventoryService`, never of `StockItem::query()`: which shelves exist and what a
     * new one is counted in are Inventory's decisions, and a decision crosses a context boundary
     * through the other module's Service (RULES.md §3).
     */
    private function resolveStockItemId(Product $product, ProductVariantData $data): ?int
    {
        if ($data->stockItemId !== null) {
            return $data->stockItemId;
        }

        if ($product->stock_item_group_id === null) {
            return null;
        }

        return (int) $this->inventory->resolveStockItemForVariant(
            (int) $product->stock_item_group_id,
            $data->widthCm,
            $data->heightCm,
        )->getKey();
    }

    private function upsertVariant(Product $product, ProductVariantData $data): ProductVariant
    {
        $attributes = [
            // Nullable, and sent on every write rather than only when present: omitting a size's
            // shelf is «افصله عن المخزون», not «اترك ما كان» — unless the product names a
            // material, in which case that is what fills it in. See resolveStockItemId().
            'stock_item_id' => $this->resolveStockItemId($product, $data),
            // Sent on every write, like the shelf above it: an omitted cost is «امسحه», not
            // «اترك ما كان». The product form holds the whole size, so a save that dropped the
            // box would otherwise leave a stale number behind for order lines to copy.
            'cost_price' => $data->costPrice,
            'label' => $data->label,
            'width_cm' => $data->widthCm,
            'height_cm' => $data->heightCm,
            'is_active' => $data->isActive,
            'sort_order' => $data->sortOrder,
        ];

        if ($data->id !== null) {
            // Scoped through the relation, so another product's variant is never found here.
            $existing = $product->variants()->whereKey($data->id)->first();

            // Refuse rather than quietly creating a duplicate — a caller that named a specific
            // variant and silently got a different one is a bug worth surfacing.
            if ($existing === null) {
                throw VariantDoesNotBelongToProduct::make($data->id, (int) $product->getKey());
            }

            $this->guardLabelIsFree($product, $data->label, exceptId: (int) $existing->getKey());

            $existing->update($attributes);

            return $existing;
        }

        // No id supplied: within a product a label is unique, so it *is* the natural key. An
        // existing size is updated rather than colliding with the unique index, which lets the
        // owner send the whole product — sizes and prices — without tracking internal ids.
        $existing = $product->variants()->where('label', $data->label)->first();

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing;
        }

        return $product->variants()->create($attributes);
    }

    /**
     * Renaming a variant onto a label another one already holds would hit the unique index and
     * surface as a 500; refusing here makes it a clear 422 instead.
     */
    private function guardLabelIsFree(Product $product, string $label, int $exceptId): void
    {
        $taken = $product->variants()
            ->where('label', $label)
            ->whereKeyNot($exceptId)
            ->exists();

        if ($taken) {
            throw DuplicateVariantLabel::make($label);
        }
    }
}
