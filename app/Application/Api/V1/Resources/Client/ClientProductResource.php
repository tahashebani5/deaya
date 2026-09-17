<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources\Client;

use App\Application\Api\V1\Resources\ProductImageResource;
use App\Application\Api\V1\Resources\ProductResource;
use App\Domain\Catalog\Enums\ProductionMode;
use App\Domain\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product, as a customer sees one.
 *
 * Roughly half of {@see ProductResource}. What is missing falls into two groups:
 *
 * **What the business does not owe anybody.** `stock_item_group` names the material and the
 * shelf; `pricing_mode` and the category's `production_mode` say how an order is executed — in
 * our own press, or sent to a vendor. Those are answers about how this shop is run.
 *
 * **What cannot arise.** `is_active` and `sort_order`: the customer is only ever sent the live
 * catalogue, in its order, so a flag saying so would be noise.
 *
 * `has_listed_prices` survives the cut although `pricing_mode` does not, and that pair is the
 * point of it — it tells the app whether to draw a price or a «اطلب عرض سعر» button without the
 * app ever learning what the modes are. The staff resource keeps it for the same reason.
 *
 * `images` reuses the staff resource: a product photo is the business's own marketing on a
 * public disk, the same picture on both screens — unlike a customer's design, which is their
 * property on a private one.
 *
 * @mixin Product
 */
class ClientProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // «P7» — what a person says out loud when they ring about it.
            'code' => $this->code,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'features' => $this->features ?? [],

            // The heading, for the filter chips. Name and id only: what the category means for
            // production is not on this side of the wall.
            'product_category' => $this->whenLoaded(
                'productCategory',
                fn () => $this->productCategory === null ? null : [
                    'id' => $this->productCategory->id,
                    'name' => $this->productCategory->name,
                ],
            ),
            'product_category_id' => $this->product_category_id,

            // What the customer is charged by — piece, kilogram — with its Arabic, so the app
            // keeps no translation table of its own.
            'pricing_unit' => $this->pricing_unit->value,
            'pricing_unit_label' => $this->pricing_unit->label(),

            // Draw a price, or draw «اطلب عرض سعر». The decided answer, not the enum behind it.
            // **Which products this one may share a basket with — a token, not the mode.**
            // «وسيط» never crosses this wall (see the note above on `production_mode`), but the
            // cart still has to be able to say «هذا يُطلب وحده» the moment somebody taps it
            // rather than at the end of a filled basket. So the server sends what the app needs
            // to compare and nothing it could decode: two products may share an order when their
            // tokens match, and the app never learns why they do.
            //
            // Null when the product has no heading — the column is nullable, see
            // PRODUCT-CATEGORIES.md — and the app treats an unknown token the way the server
            // does, as work of ours. The refusal that matters is enforced in `CreateOrder`
            // regardless of what any client believes.
            'order_group' => $this->productCategory?->productionMode()->orderGroup()
                ?? ProductionMode::InHouse->orderGroup(),

            'has_listed_prices' => $this->pricing_mode->hasListedPrices(),

            'min_order_quantity' => (string) $this->min_order_quantity,

            'variants' => ClientProductVariantResource::collection($this->whenLoaded('variants')),
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
        ];
    }
}
