<?php

declare(strict_types=1);

namespace App\Domain\Customer\DTOs;

final readonly class CustomerShopData
{
    public function __construct(
        public string $name,
        /** Where the shop is, on the delivery map. Required — it is what identifies the place. */
        public int $cityId,
        /** Which neighbourhood inside that city, or null when the city has none or nobody knows yet. */
        public ?int $regionId = null,
        /**
         * Degrees, -90 to 90, or null.
         *
         * **Null means «لم يُرسَل», not «امسحه».** The form no longer asks for a pin, so every
         * request the app makes leaves both coordinates out — and a shop that was recorded with
         * one must keep it. {@see SyncCustomerShops} is where that is enforced; clearing a pin
         * is not something any caller can ask for today.
         */
        public ?float $latitude = null,
        /** Degrees, -180 to 180, or null. Same «not sent» meaning as {@see $latitude}. */
        public ?float $longitude = null,
        public ?string $pageUrl = null,
        /**
         * مجال العمل — which trade this shop is in, or null for one recorded without it.
         *
         * Null is a real answer, not a missing one: every shop recorded before this field
         * existed has none, and «لم يُحدَّد» is also the honest state for a shop entered in a
         * hurry. Both `store` and `update` send the whole shop, so omitting it clears it.
         */
        public ?int $businessFieldId = null,
        /** Present when updating an existing shop; null when creating a new one. */
        public ?int $id = null,
    ) {}

    /**
     * @param  array<string, mixed>  $shop  One validated entry from the request's `shops` array.
     */
    public static function fromArray(array $shop): self
    {
        return new self(
            name: (string) $shop['name'],
            cityId: (int) $shop['city_id'],
            regionId: isset($shop['region_id']) && $shop['region_id'] !== '' ? (int) $shop['region_id'] : null,
            latitude: isset($shop['latitude']) ? (float) $shop['latitude'] : null,
            longitude: isset($shop['longitude']) ? (float) $shop['longitude'] : null,
            pageUrl: isset($shop['page_url']) && $shop['page_url'] !== '' ? (string) $shop['page_url'] : null,
            businessFieldId: isset($shop['business_field_id']) && $shop['business_field_id'] !== ''
                ? (int) $shop['business_field_id']
                : null,
            id: isset($shop['id']) ? (int) $shop['id'] : null,
        );
    }
}
