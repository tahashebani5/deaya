<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Customer\Models\CustomerShop;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerShop
 */
class CustomerShopResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,

            // الموقع. The ids for a form to preselect, and the names so a screen never has to
            // fetch the delivery map to translate two numbers — the same bargain
            // `business_field` below makes.
            //
            // `city` can be null while `city_id` is not: a soft-deleted city is out of scope, so
            // a shop left pointing at one shows no city until somebody picks another.
            'city_id' => $this->city_id,
            'city' => $this->whenLoaded('city', fn () => $this->city ? new CityResource($this->city) : null),
            'region_id' => $this->region_id,
            'region' => $this->whenLoaded('region', fn () => $this->region ? new RegionResource($this->region) : null),

            // Numbers, not strings — a map SDK can use these directly. Null for every shop
            // recorded since the form stopped asking for a pin, which is most of them.
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'page_url' => $this->page_url,

            // The id for a form to preselect, and the whole field for a screen to render —
            // sending the id alone would make every client fetch the list to translate one
            // number. Null for a shop recorded without a trade, which is most of the old ones.
            'business_field_id' => $this->business_field_id,
            'business_field' => $this->whenLoaded(
                'businessField',
                fn () => new BusinessFieldResource($this->businessField),
            ),
        ];
    }
}
