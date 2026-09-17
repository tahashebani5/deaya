<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Customer;

use App\Domain\Customer\Models\BusinessField;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Same shape as creating; only the uniqueness rule differs, because a field keeping its own
 * name must not collide with itself.
 *
 * The rules are written out in full rather than merged onto the parent's on purpose: Scramble
 * reads this method statically to build the OpenAPI request body and cannot follow an
 * `array_merge(parent::rules(), …)` call — doing that leaves the endpoint with no documented body.
 */
class UpdateBusinessFieldRequest extends StoreBusinessFieldRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100', $this->nameUniqueAmongOtherFields()],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }

    private function nameUniqueAmongOtherFields(): Unique
    {
        /** @var BusinessField $field */
        $field = $this->route('business_field');

        return Rule::unique('business_fields', 'name')->ignore($field->getKey())->withoutTrashed();
    }
}
