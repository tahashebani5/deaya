<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Queries;

use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * One city's neighbourhoods.
 *
 * Always scoped to a city — there is no listing of every region in the country, because there
 * is no screen that would want one.
 */
final class RegionListQuery
{
    /**
     * @return LengthAwarePaginator<int, Region>
     */
    public function __invoke(City $city, ?string $search = null, int $perPage = 15): LengthAwarePaginator
    {
        // The relation already orders by name, which is the order a picker should render.
        return $city->regions()
            ->when($search !== null && $search !== '', fn ($q) => $q->where('name', 'ilike', '%'.$search.'%'))
            ->paginate($perPage);
    }
}
