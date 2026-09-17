<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\DTOs\RegionData;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;

final class CreateRegion
{
    public function __invoke(City $city, RegionData $data): Region
    {
        // Through the relation, so city_id is set by the association. It is not fillable on
        // Region precisely so that no payload can decide which city a neighbourhood lands in.
        return $city->regions()->create([
            'name' => $data->name,
            'code' => $data->code,
            'darb_branch' => $data->darbBranch,
            'nawris_area_id' => $data->nawrisAreaId,
            'latitude' => $data->latitude,
            'longitude' => $data->longitude,
        ]);
    }
}
