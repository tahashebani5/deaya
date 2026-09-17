<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\DTOs\CityData;
use App\Domain\Delivery\Models\City;

/**
 * The whole city is replaced by what was sent, so omitting `latitude`/`longitude` clears the pin
 * rather than keeping it. That is what PUT means, and it keeps "save the form" from silently
 * preserving a value the user just cleared.
 *
 * `fulfilment_type` is the one exception, for the same reason `is_active` is one on a customer:
 * clearing a pin is visible on the next screen, whereas a branch quietly becoming a delivery
 * city would only surface as orders moving to the wrong status days later.
 */
final class UpdateCity
{
    public function __invoke(City $city, CityData $data): City
    {
        $city->update([
            'name' => $data->name,
            'fulfilment_type' => $data->fulfilmentType ?? $city->fulfilment_type,
            'is_region_required' => $data->isRegionRequired,
            'delivery_price' => $data->deliveryPrice,
            'darb_branch' => $data->darbBranch,
            'nawris_government_id' => $data->nawrisGovernmentId,
            'latitude' => $data->latitude,
            'longitude' => $data->longitude,
        ]);

        return $city->loadCount('regions');
    }
}
