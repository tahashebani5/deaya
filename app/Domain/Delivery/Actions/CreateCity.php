<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\DTOs\CityData;
use App\Domain\Delivery\Enums\FulfilmentType;
use App\Domain\Delivery\Models\City;

final class CreateCity
{
    public function __invoke(CityData $data): City
    {
        $city = City::create([
            'name' => $data->name,
            // Somewhere we deliver to unless the caller says otherwise: that is what all but two
            // of the ninety-five rows on the map are.
            'fulfilment_type' => $data->fulfilmentType ?? FulfilmentType::Delivery,
            'is_region_required' => $data->isRegionRequired,
            'delivery_price' => $data->deliveryPrice,
            'darb_branch' => $data->darbBranch,
            'nawris_government_id' => $data->nawrisGovernmentId,
            'latitude' => $data->latitude,
            'longitude' => $data->longitude,
        ]);

        // A brand-new city has none, but the count must still be present: the resource renders
        // it, and strict mode turns a missing attribute into an exception rather than a null.
        return $city->loadCount('regions');
    }
}
