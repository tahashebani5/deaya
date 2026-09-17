<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources\Client;

use App\Application\Api\V1\Resources\CityResource;
use App\Domain\Delivery\Models\City;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Somewhere an order can be sent, as the customer app draws it.
 *
 * **What is missing, and why each one is.** `darb_branch` and `nawris_government_id` name the
 * carrier we hand this destination to — an arrangement between the shop and them, and a field
 * that tells every customer also tells every competitor. `latitude`/`longitude` feed a staff map
 * this app does not draw. `regions_count` is a list-screen affordance for a picker that pages;
 * this one does not page, so the regions are simply here.
 *
 * **`delivery_price` *is* sent**, and that is the one deliberate inclusion rather than a
 * deliberate absence: what delivery costs is a number the customer pays, and a picker that hides
 * it is a picker that surprises somebody at the end of a checkout. Null means no rate has been
 * agreed for this city yet — which is not «free», and the app must draw the two differently.
 *
 * {@see CityResource} is the staff view, and it is not extended here for the reason no client
 * resource extends its staff twin: `Gate::before` is typehinted on `User`, so a resource that
 * can reach a permission check is a resource that throws when a customer renders it.
 *
 * @mixin City
 */
class ClientCityResource extends JsonResource
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
            // boolean the order screen actually branches on — so the client never has to know
            // which enum case means «they collect it».
            'fulfilment_type' => $this->fulfilment_type->value,
            'fulfilment_type_label' => $this->fulfilment_type->label(),
            'is_office_pickup' => $this->isOfficePickup(),

            // Whether the picker may let the customer past without choosing a neighbourhood.
            'is_region_required' => $this->is_region_required,

            // A string, never a float: this is money, and "15.00" survives a client's JSON
            // parser intact where 15.0 does not.
            'delivery_price' => $this->delivery_price !== null ? (string) $this->delivery_price : null,

            'regions' => ClientRegionResource::collection($this->whenLoaded('regions')),
        ];
    }
}
