<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Actions;

use App\Domain\Carrier\Exceptions\CityHasNoNawrisMapping;
use App\Domain\Delivery\DeliveryService;
use App\Domain\Delivery\Models\ShippingCompany;
use App\Domain\Order\Models\Order;

/**
 * Turns the order's own city and region into the destination Nawris understands.
 *
 * **Nobody picks a Nawris destination by hand.** The order already knows where it is going, and
 * the city carries the mapping — see NAWRIS-INTEGRATION.md §4. This runs exactly once, at
 * dispatch; every later edit replays what it produced from the parcel row rather than calling it
 * again, because re-deriving a destination mid-journey moves the parcel.
 */
final class ResolveNawrisDestination
{
    /**
     * @param  array<string, mixed>  $config  `services.nawris`
     */
    public function __construct(
        private readonly array $config,
        private readonly DeliveryService $delivery,
    ) {}

    /**
     * @throws CityHasNoNawrisMapping
     */
    public function __invoke(Order $order): NawrisDestination
    {
        $order->loadMissing(['city', 'region']);

        $government = $order->city?->nawris_government_id;

        if ($government === null || trim($government) === '') {
            // By name, so somebody can go and map that city — see the exception.
            throw CityHasNoNawrisMapping::make((string) $order->city_name);
        }

        return new NawrisDestination(
            government: $government,
            area: $order->region?->nawris_area_id,
            shippingCompanyId: $this->carrier(),
        );
    }

    /**
     * Which of *our* `shipping_companies` rows this parcel is filed under.
     *
     * **The setting first, and the shop's default only when it is unset.** The two answer
     * different questions and both are real: `services.nawris.shipping_company_id` names the row
     * *this integration's* parcels belong to, which stays true even if the business starts
     * sending most parcels with somebody else, while «الشركة الافتراضية» is who we usually send
     * with — see {@see ShippingCompany}. Reading the default second closes the case that produced
     * two truths about one parcel: a deployment with the setting blank filed every parcel under
     * nobody while the order it belonged to named a carrier.
     *
     * Null when neither exists, and the parcel is lodged anyway. Who it is filed under is
     * bookkeeping; refusing a real shipment over it would be the tail wagging the dog.
     */
    private function carrier(): ?int
    {
        $configured = $this->config['shipping_company_id'] ?? null;

        if ($configured !== null && $configured !== '') {
            return (int) $configured;
        }

        return $this->delivery->defaultShippingCompany()?->getKey();
    }
}
