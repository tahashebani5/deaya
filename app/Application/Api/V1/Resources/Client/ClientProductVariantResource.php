<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources\Client;

use App\Application\Api\V1\Resources\ProductPriceTierResource;
use App\Application\Api\V1\Resources\ProductVariantResource;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A size, as the person buying it sees one.
 *
 * **Three things {@see ProductVariantResource} carries are gone, and each for its own reason.**
 *
 * `cost_price` — what the shop pays. The staff resource hides it behind
 * `$request->user()?->can(...)`, which is exactly the call a customer cannot make: `Customer`
 * takes the bare `Authenticatable` contract and has no `can()` at all. So this is not the staff
 * resource with a permission that happens to be false — it is a different resource that has
 * never heard of the number. That distinction is the whole reason the client family exists; a
 * resource shared between the two apps is one `can()` away from a leak.
 *
 * `stock_item` — which shelf this size draws from. A warehouse fact: it says what we hold, at
 * what size, under which material. Nobody outside the building is owed it.
 *
 * `is_active` / `sort_order` — the catalogue's own housekeeping. The customer is only ever sent
 * live sizes, so the flag would answer a question that cannot arise.
 *
 * `price_tiers` stays, and reuses the staff resource: a price break is the selling price, the
 * same number on both screens, and it already renders money as decimal strings rather than
 * floats.
 *
 * @mixin ProductVariant
 */
class ClientProductVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // «25*35» — what the customer picks from.
            'label' => $this->label,
            'width_cm' => $this->width_cm,
            'height_cm' => $this->height_cm,

            'price_tiers' => ProductPriceTierResource::collection($this->whenLoaded('priceTiers')),
        ];
    }
}
