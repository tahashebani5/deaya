<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Vendor;

use App\Domain\Vendor\Models\Vendor;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Same shape as creating; only the uniqueness rule differs, because a vendor keeping its own
 * phone number must not collide with itself.
 *
 * Written out in full rather than merged onto the parent's rules — Scramble reads this method
 * statically to build the OpenAPI request body and cannot follow an
 * `array_merge(parent::rules(), …)` call, the same trap `UpdateCustomerRequest` and
 * `UpdateWarehouseRequest` avoid.
 */
class UpdateVendorRequest extends StoreVendorRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\d{9,15}$/', $this->phoneUniqueAmongOtherVendors()],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            // Omit to leave the vendor's current state alone.
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * No two vendors may share a phone number, but a vendor keeping its own is fine.
     */
    private function phoneUniqueAmongOtherVendors(): Unique
    {
        /** @var Vendor $vendor */
        $vendor = $this->route('vendor');

        return Rule::unique('vendors', 'phone')->ignore($vendor->getKey())->withoutTrashed();
    }
}
