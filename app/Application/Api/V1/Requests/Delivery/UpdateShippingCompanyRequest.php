<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Delivery;

use App\Domain\Delivery\Models\ShippingCompany;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Same shape as creating; only the uniqueness rule differs, because a company keeping its own
 * name must not collide with itself.
 *
 * Written out in full rather than merged onto the parent's, for the reason UpdateCityRequest
 * gives: Scramble reads this method statically and cannot follow an `array_merge`.
 */
class UpdateShippingCompanyRequest extends StoreShippingCompanyRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100', $this->nameUniqueAmongOthers()],
            'phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    private function nameUniqueAmongOthers(): Unique
    {
        /** @var ShippingCompany $company */
        $company = $this->route('shipping_company');

        return Rule::unique('shipping_companies', 'name')
            ->ignore($company->getKey())
            ->withoutTrashed();
    }
}
