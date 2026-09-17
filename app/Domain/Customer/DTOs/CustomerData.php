<?php

declare(strict_types=1);

namespace App\Domain\Customer\DTOs;

final readonly class CustomerData
{
    /**
     * @param  list<CustomerShopData>|null  $shops  null means "not supplied" — on update the
     *                                              existing shops are left untouched. An empty
     *                                              array means "remove every shop".
     */
    public function __construct(
        public string $name,
        public string $phone,
        /**
         * null means "not supplied". On create that becomes active; on update the current
         * value is kept, so omitting the field can never silently reactivate a customer.
         */
        public ?bool $isActive = null,
        public ?array $shops = null,
    ) {}

    /**
     * Built from already-validated request data. This is the one place an array crosses
     * into the domain; everything deeper takes typed objects.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            name: (string) $validated['name'],
            phone: (string) $validated['phone'],
            isActive: array_key_exists('is_active', $validated) && $validated['is_active'] !== null
                ? (bool) $validated['is_active']
                : null,
            shops: array_key_exists('shops', $validated) && is_array($validated['shops'])
                ? array_map(CustomerShopData::fromArray(...), $validated['shops'])
                : null,
        );
    }
}
