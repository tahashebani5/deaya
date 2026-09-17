<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\DTOs\CustomerShopData;
use App\Domain\Customer\Exceptions\ShopDoesNotBelongToCustomer;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerShop;

/**
 * Makes a customer's shops match the given set exactly.
 *
 * Entries carrying an `id` update that shop, entries without one create a new shop, and any
 * existing shop missing from the set is removed. Shops belong to their customer and have no
 * life of their own, so replacing the whole set is the honest operation.
 */
final class SyncCustomerShops
{
    /**
     * @param  list<CustomerShopData>  $shops
     */
    public function __invoke(Customer $customer, array $shops): void
    {
        $keptIds = [];

        foreach ($shops as $shop) {
            $attributes = [
                'name' => $shop->name,
                'city_id' => $shop->cityId,
                'region_id' => $shop->regionId,
                'page_url' => $shop->pageUrl,
                'business_field_id' => $shop->businessFieldId,
            ];

            // The one field this action does *not* replace. Everything else here is overwritten
            // by what was sent — that is what syncing a whole shop means — but the form stopped
            // asking for a pin, so an omitted coordinate means «لم يُرسَل» and not «امسحه».
            // Treating it like the rest would empty, on the first edit, the very columns kept
            // for the day the map comes back.
            if ($shop->latitude !== null && $shop->longitude !== null) {
                $attributes['latitude'] = $shop->latitude;
                $attributes['longitude'] = $shop->longitude;
            }

            if ($shop->id !== null) {
                // Scoped through the relation, so another customer's shop is never found here.
                $existing = $customer->shops()->whereKey($shop->id)->first();

                // Refuse rather than silently creating a duplicate: a caller that named a
                // specific shop and got a different one is a bug worth surfacing.
                if ($existing === null) {
                    throw ShopDoesNotBelongToCustomer::make($shop->id, (int) $customer->getKey());
                }

                $existing->update($attributes);
                $keptIds[] = $existing->getKey();

                continue;
            }

            $keptIds[] = $customer->shops()->create($attributes)->getKey();
        }

        // One model at a time rather than a mass delete on the relation: a mass delete fires no
        // model events, so a shop dropped from the set would leave the API with nothing in the
        // audit trail to say it ever existed. A customer has a handful of shops.
        $customer->shops()
            ->whereKeyNot($keptIds)
            ->each(fn (CustomerShop $shop) => $shop->delete());
    }
}
