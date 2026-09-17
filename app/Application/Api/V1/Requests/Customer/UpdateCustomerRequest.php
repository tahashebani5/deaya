<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Customer;

use App\Domain\Customer\Models\Customer;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Same shape as creating, plus an optional `shops.*.id` so an existing shop can be edited in
 * place instead of being replaced.
 *
 * The rules are written out in full rather than merged onto the parent's on purpose: Scramble
 * reads this method statically to build the OpenAPI request body, and it cannot follow a
 * `array_merge(parent::rules(), …)` call — doing that left this endpoint with no documented
 * body at all.
 */
class UpdateCustomerRequest extends StoreCustomerRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            // Digits only, wide enough for both Libyan mobiles (0912345678) and
            // landlines (0213334444). Unique across customers, ignoring this one — otherwise
            // saving the form without touching the phone would collide with itself.
            'phone' => ['required', 'string', 'regex:/^\d{9,15}$/', $this->phoneUniqueAmongOtherCustomers()],
            // Omit to leave the customer's current state alone.
            'is_active' => ['sometimes', 'boolean'],

            // Omit `shops` to keep the existing ones. Sending the key replaces the whole set:
            // entries with an `id` are updated, entries without one are added, and anything
            // left out is deleted.
            'shops' => ['sometimes', 'array'],
            'shops.*.id' => ['sometimes', 'integer', $this->shopBelongsToThisCustomer()],
            'shops.*.name' => ['required', 'string', 'max:255'],
            // الموقع: a place on the delivery map. The region must also be *inside* the chosen
            // city, which `withValidator()` on the parent checks and this endpoint inherits.
            'shops.*.city_id' => ['required', 'integer', Rule::exists('cities', 'id')->withoutTrashed()],
            'shops.*.region_id' => ['nullable', 'integer', Rule::exists('regions', 'id')->withoutTrashed()],
            // الإحداثيات, no longer asked for by any screen — see StoreCustomerRequest. Leaving
            // them out keeps whatever the shop already had rather than clearing it.
            'shops.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'shops.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'shops.*.page_url' => ['nullable', 'url', 'max:2048'],
            // مجال العمل. Optional — most shops on record have none — and `exists` keeps a
            // stale id from a client's cached list out of the database. Deactivated fields are
            // still accepted: a shop already recorded under one must survive being saved again.
            'shops.*.business_field_id' => ['nullable', 'integer', Rule::exists('business_fields', 'id')->withoutTrashed()],
        ];
    }

    /**
     * No two customers may share a phone number, but a customer keeping their own is fine.
     */
    private function phoneUniqueAmongOtherCustomers(): Unique
    {
        /** @var Customer $customer */
        $customer = $this->route('customer');

        return Rule::unique('customers', 'phone')->ignore($customer->getKey())->withoutTrashed();
    }

    /**
     * Restricts a supplied shop id to this customer's own shops, so one customer's request
     * can never reach into another's data.
     */
    private function shopBelongsToThisCustomer(): Exists
    {
        /** @var Customer $customer */
        $customer = $this->route('customer');

        return Rule::exists('customer_shops', 'id')->where('customer_id', $customer->getKey());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'shops.*.id.exists' => 'المحل المحدد لا ينتمي لهذا العميل',
        ]);
    }
}
