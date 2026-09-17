<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\Exceptions\CityRequiresAtLeastOneRegion;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a region, with the one rule that stands between the delivery map and a checkout the
 * customer cannot finish.
 */
final class DeleteRegion
{
    public function __invoke(Region $region): void
    {
        DB::transaction(function () use ($region): void {
            // The *city* row is locked, not the regions. Two requests each deleting one of the
            // last two regions would otherwise both count 2, both pass, and leave zero behind;
            // serialising them on the parent row makes the second see the first's result.
            //
            // Locking the parent is also the only form PostgreSQL accepts here — FOR UPDATE
            // cannot be combined with an aggregate, so `count()` can never carry the lock.
            $city = City::query()->whereKey($region->city_id)->lockForUpdate()->firstOrFail();

            if ($city->is_region_required && $city->regions()->count() <= 1) {
                throw CityRequiresAtLeastOneRegion::make();
            }

            $region->delete();
        });
    }
}
