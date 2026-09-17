<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Delivery\Models\City;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin City
 */
class CityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,

            // Value for logic, label so the app keeps no translation table of its own, and the
            // boolean the order screen actually branches on — so a client never has to know
            // which enum case means "they collect it".
            'fulfilment_type' => $this->fulfilment_type->value,
            'fulfilment_type_label' => $this->fulfilment_type->label(),
            'is_office_pickup' => $this->isOfficePickup(),

            'is_region_required' => $this->is_region_required,

            // A string, never a float: this is money, and "15.00" survives a client's JSON
            // parser intact where 15.0 does not. null means no rate has been agreed yet, which
            // is a different thing from "0.00" — free delivery.
            'delivery_price' => $this->delivery_price !== null ? (string) $this->delivery_price : null,

            'darb_branch' => $this->darb_branch,

            // Nawris's own name for this destination, frozen onto a parcel at dispatch. Null
            // means the city is not a Nawris destination, which dispatch refuses by name
            // rather than by sending a null the carrier would reject unreadably.
            'nawris_government_id' => $this->nawris_government_id,

            // Numbers, so a map SDK can use them without parsing.
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,

            // Present on lists (counted) and on a single city (loaded) respectively — never
            // both queries at once.
            'regions_count' => $this->whenCounted('regions'),
            'regions' => RegionResource::collection($this->whenLoaded('regions')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
