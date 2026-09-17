<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

use App\Domain\Delivery\Actions\CreateCity;
use App\Domain\Delivery\Actions\CreateRegion;
use App\Domain\Delivery\Actions\CreateShippingCompany;
use App\Domain\Delivery\Actions\DeleteCity;
use App\Domain\Delivery\Actions\DeleteRegion;
use App\Domain\Delivery\Actions\DeleteShippingCompany;
use App\Domain\Delivery\Actions\UpdateCity;
use App\Domain\Delivery\Actions\UpdateRegion;
use App\Domain\Delivery\Actions\UpdateShippingCompany;
use App\Domain\Delivery\DTOs\CityData;
use App\Domain\Delivery\DTOs\RegionData;
use App\Domain\Delivery\DTOs\ShippingCompanyData;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use App\Domain\Delivery\Models\ShippingCompany;
use App\Domain\Delivery\Queries\CityFilters;
use App\Domain\Delivery\Queries\CityListQuery;
use App\Domain\Delivery\Queries\RegionListQuery;
use App\Domain\Delivery\Queries\ShippingCompanyFilters;
use App\Domain\Delivery\Queries\ShippingCompanyListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Delivery module's public front door.
 *
 * When Orders arrive, they resolve a delivery address and its price through here — never by
 * reading `cities` themselves. That seam is what lets the delivery map change shape internally
 * without every caller changing with it.
 */
class DeliveryService
{
    public function __construct(
        private readonly CreateCity $createCity,
        private readonly UpdateCity $updateCity,
        private readonly DeleteCity $deleteCity,
        private readonly CreateRegion $createRegion,
        private readonly UpdateRegion $updateRegion,
        private readonly DeleteRegion $deleteRegion,
        private readonly CityListQuery $cityListQuery,
        private readonly RegionListQuery $regionListQuery,
        private readonly CreateShippingCompany $createShippingCompany,
        private readonly UpdateShippingCompany $updateShippingCompany,
        private readonly DeleteShippingCompany $deleteShippingCompany,
        private readonly ShippingCompanyListQuery $shippingCompanyListQuery,
    ) {}

    /**
     * @return LengthAwarePaginator<int, City>
     */
    public function paginateCities(CityFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return ($this->cityListQuery)($filters, $perPage);
    }

    /**
     * Everywhere an order can be sent, with the regions inside each one.
     *
     * **Not paged, and not filtered.** This is the customer app's destination picker, which is
     * one screen that has to contain every answer — a paged picker is a picker somebody scrolls
     * off the end of and concludes we do not deliver to their city. The list is a country's
     * worth of places and a handful of neighbourhoods each; it is loaded once and kept.
     *
     * Ordered by id for the reason `CityListQuery` is: insertion order *is* the business's
     * order, the office-pickup branches were seeded first and belong at the top, and sorting
     * Arabic names alphabetically depends on the database's collation.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, City>
     */
    public function deliveryMap(): \Illuminate\Database\Eloquent\Collection
    {
        return City::query()->with('regions')->orderBy('id')->get();
    }

    /**
     * One city by id, for a caller that received it in a request body rather than through
     * route-model binding — an order names its destination that way.
     *
     * Here rather than in Order, because Order must never query City directly: that seam is
     * what lets the delivery map change internally without a ripple.
     */
    public function findCity(int $id): City
    {
        return City::query()->findOrFail($id);
    }

    /** One region by id. Whether it belongs to the chosen city is the caller's rule to enforce. */
    public function findRegion(int $id): Region
    {
        return Region::query()->findOrFail($id);
    }

    public function createCity(CityData $data): City
    {
        return ($this->createCity)($data);
    }

    public function updateCity(City $city, CityData $data): City
    {
        return ($this->updateCity)($city, $data);
    }

    /**
     * The regions inside go with it — they have no life outside their city.
     *
     * Soft, since every model here soft deletes: the city and its regions leave the API and
     * keep their history, and the audit trail records who removed them.
     *
     * 🎯 Revisit when Orders lands: an order that names a deleted city would lose its delivery
     * address, so this will likely become a deactivation the way customers and products are.
     */
    public function deleteCity(City $city): void
    {
        ($this->deleteCity)($city);
    }

    /**
     * @return LengthAwarePaginator<int, Region>
     */
    public function paginateRegions(City $city, ?string $search = null, int $perPage = 15): LengthAwarePaginator
    {
        return ($this->regionListQuery)($city, $search, $perPage);
    }

    public function createRegion(City $city, RegionData $data): Region
    {
        return ($this->createRegion)($city, $data);
    }

    public function updateRegion(Region $region, RegionData $data): Region
    {
        return ($this->updateRegion)($region, $data);
    }

    public function deleteRegion(Region $region): void
    {
        ($this->deleteRegion)($region);
    }

    // ─────────────────────────── the carriers ───────────────────────────

    /**
     * @return LengthAwarePaginator<int, ShippingCompany>
     */
    public function paginateShippingCompanies(
        ShippingCompanyFilters $filters,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return ($this->shippingCompanyListQuery)($filters, $perPage);
    }

    public function findShippingCompany(int $id): ShippingCompany
    {
        return ShippingCompany::query()->findOrFail($id);
    }

    /**
     * The carrier a dispatch form opens on, or null when nobody named one.
     *
     * **Asked of the list rather than guessed from it.** The app fills the field in by itself
     * when the business deals with exactly one company and gives the choice back the moment
     * there is a second — which is most shops, and which is why «من سيأخذها» was answered by
     * hand thirty times a day for a company that takes nine parcels in ten.
     *
     * `is_active` as well as the flag, though the actions do not let the two disagree: the
     * picker refuses a retired carrier, so a form opening on one would be a suggestion the next
     * screen contradicts.
     */
    public function defaultShippingCompany(): ?ShippingCompany
    {
        return ShippingCompany::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    public function createShippingCompany(ShippingCompanyData $data): ShippingCompany
    {
        return ($this->createShippingCompany)($data);
    }

    public function updateShippingCompany(
        ShippingCompany $company,
        ShippingCompanyData $data,
    ): ShippingCompany {
        return ($this->updateShippingCompany)($company, $data);
    }

    /**
     * Removes it from the list. The orders it carried keep naming it — see
     * {@see DeleteShippingCompany}.
     */
    public function deleteShippingCompany(ShippingCompany $company): void
    {
        ($this->deleteShippingCompany)($company);
    }
}
