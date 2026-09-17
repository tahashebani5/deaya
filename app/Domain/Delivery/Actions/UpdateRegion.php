<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\DTOs\RegionData;
use App\Domain\Delivery\Models\Region;

/**
 * Replaces the region's own fields. Its city is never among them — moving a neighbourhood
 * between cities is not an edit, and there is no endpoint that offers it.
 */
final class UpdateRegion
{
    public function __invoke(Region $region, RegionData $data): Region
    {
        $region->update([
            'name' => $data->name,
            'code' => $data->code,
            'darb_branch' => $data->darbBranch,
            'nawris_area_id' => $data->nawrisAreaId,
            'latitude' => $data->latitude,
            'longitude' => $data->longitude,
        ]);

        return $region;
    }
}
