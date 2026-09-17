<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // What a person says out loud — P7. Always 'P' + the id, so it is safe to read as
            // one in support.
            'code' => $this->code,

            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'features' => $this->features ?? [],

            // Each enum ships its value *and* its Arabic label, so the app never has to keep its
            // own translation table in step with the backend's.
            // «التصنيف» — the catalogue heading, and the only thing that classifies a product
            // since «النوع» became two rows in the same table. A whole object rather than an id,
            // because
            // every screen showing a product shows the name, and an app that looks one up per
            // row is an app making N requests to draw a list. Null only for a product recorded
            // before categories existed and not yet edited since.
            'product_category' => $this->whenLoaded(
                'productCategory',
                fn () => $this->productCategory === null ? null : [
                    'id' => $this->productCategory->id,
                    'name' => $this->productCategory->name,
                    // **The effective mode, not the row's own** — the opposite of what
                    // `ProductCategoryResource` sends, and deliberately so. That one feeds an edit
                    // form, which must put back what the row itself says; this one answers «كيف
                    // يُنفَّذ هذا المنتج؟», where a heading's answer reaches the headings under it.
                    // It is what tells a screen to ask for a vendor and to show a cost box at all.
                    'production_mode' => $this->productCategory->productionMode()->value,
                    'production_mode_label' => $this->productCategory->productionMode()->label(),
                ],
            ),
            'product_category_id' => $this->product_category_id,

            // What this product is made of. Null for one whose material nobody has named — a
            // quote-only product, or one whose sizes come from several materials and are linked
            // individually. When set, every size resolves to this material's shelf at its own
            // size on save.
            'stock_item_group_id' => $this->stock_item_group_id,
            'stock_item_group' => $this->whenLoaded(
                'stockItemGroup',
                fn (): ?array => $this->stockItemGroup === null ? null : [
                    'id' => $this->stockItemGroup->id,
                    'code' => $this->stockItemGroup->code,
                    'name' => $this->stockItemGroup->name,
                    'default_unit' => $this->stockItemGroup->default_unit->value,
                    'default_unit_label' => $this->stockItemGroup->default_unit->label(),
                ],
            ),
            'pricing_unit' => $this->pricing_unit->value,
            'pricing_unit_label' => $this->pricing_unit->label(),

            // What the warehouse counts this in — only equal to `pricing_unit` until someone
            // calls `PATCH products/{product}/stock-unit` to say otherwise.

            'pricing_mode' => $this->pricing_mode->value,
            'pricing_mode_label' => $this->pricing_mode->label(),

            // Tells the client whether to render a price or a "request a quote" button, without
            // it having to interpret the enum itself.
            'has_listed_prices' => $this->pricing_mode->hasListedPrices(),

            'min_order_quantity' => (string) $this->min_order_quantity,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,

            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
            'images' => ProductImageResource::collection($this->whenLoaded('images')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
