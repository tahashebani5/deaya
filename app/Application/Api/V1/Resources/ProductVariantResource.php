<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Enums\PermissionName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductVariant
 */
class ProductVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'width_cm' => $this->width_cm,
            'height_cm' => $this->height_cm,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,

            // **سعر التكلفة، and the key is absent rather than null without the grant.** Null
            // would read as «هذا المقاس بلا تكلفة» — a fact about the product — where the truth
            // is «لست ممن يرونها»; a screen cannot tell those apart from the same value. The
            // clerk taking an order holds `products.view` and not this, so the number the shop
            // pays never reaches the order form. Same split `inventory.view_cost` draws over
            // stock, and the same reason.
            'cost_price' => $this->when(
                $request->user()?->can(PermissionName::ViewProductCost->value) === true,
                fn () => $this->cost_price === null ? null : (string) $this->cost_price,
            ),

            // Which shelf this size draws from. Null for a size that is never stocked — a
            // quote-only product has no pile behind it, and every stock path refuses such a size
            // by name rather than pretending it has one.
            'stock_item_id' => $this->stock_item_id,
            'stock_item' => $this->whenLoaded('stockItem', fn (): ?array => $this->stockItem === null ? null : [
                'id' => $this->stockItem->id,
                'code' => $this->stockItem->code,
                'name' => $this->stockItem->name,
                'width_cm' => $this->stockItem->width_cm,
                'height_cm' => $this->stockItem->height_cm,
                'display_name' => $this->stockItem->displayName(),
                'unit' => $this->stockItem->unit->value,
                'unit_label' => $this->stockItem->unit->label(),
            ]),

            // Which product this size belongs to, where a caller loaded it. A size is «المنتج —
            // المقاس» to a reader and «25*35» on its own to nobody, and the one screen that lists
            // sizes across several products at once cannot name them without this.
            'product' => $this->whenLoaded('product', fn (): ?array => $this->product === null ? null : [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ]),

            'price_tiers' => ProductPriceTierResource::collection($this->whenLoaded('priceTiers')),
        ];
    }
}
