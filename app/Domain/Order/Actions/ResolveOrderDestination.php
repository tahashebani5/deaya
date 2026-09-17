<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Delivery\DeliveryService;
use App\Domain\Order\DTOs\OrderDestination;
use App\Domain\Order\Exceptions\RegionDoesNotBelongToCity;
use App\Domain\Order\Exceptions\RegionRequiredForCity;

/**
 * Turns a city id and an optional region id into the facts an order stores about where it goes.
 *
 * Three things happen here and nowhere else: the region is checked against the city that claims
 * it, the "this city needs a neighbourhood" rule is enforced, and the delivery price is copied
 * out of the map. Doing it in one place is what stops create and update drifting apart — an
 * order created correctly and then updated into an impossible address is the bug this prevents.
 *
 * Reaches the delivery map through {@see DeliveryService}, never through the City model:
 * dependencies run one way, and Delivery must stay ignorant that orders exist.
 */
final class ResolveOrderDestination
{
    public function __construct(private readonly DeliveryService $delivery) {}

    /**
     * @throws RegionRequiredForCity
     * @throws RegionDoesNotBelongToCity
     */
    public function __invoke(int $cityId, ?int $regionId): OrderDestination
    {
        $city = $this->delivery->findCity($cityId);

        $region = $regionId !== null ? $this->delivery->findRegion($regionId) : null;

        if ($region !== null && (int) $region->city_id !== (int) $city->getKey()) {
            throw RegionDoesNotBelongToCity::make((int) $region->getKey(), $city->name);
        }

        if ($city->is_region_required && $region === null) {
            throw RegionRequiredForCity::make($city->name);
        }

        return new OrderDestination(
            cityId: (int) $city->getKey(),
            regionId: $region !== null ? (int) $region->getKey() : null,
            cityName: $city->name,
            regionName: $region?->name,
            fulfilmentType: $city->fulfilment_type,
            // A city nobody has agreed a rate for yet costs nothing to deliver to *on this
            // order*, rather than blocking the order outright. The null on the city means "not
            // priced"; charging a guess would be worse than charging zero and correcting it.
            deliveryPrice: $city->hasDeliveryPrice() ? (string) $city->delivery_price : '0.00',
        );
    }
}
