<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Delivery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShippingCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // One name, one company. `withoutTrashed` because the unique index ignores removed
            // rows, and a rule that disagreed with the index would refuse what the database
            // would have accepted.
            'name' => [
                'required', 'string', 'min:2', 'max:100',
                Rule::unique('shipping_companies', 'name')->withoutTrashed(),
            ],

            /** The office you ring when a parcel goes missing. */
            'phone' => ['nullable', 'string', 'max:20'],

            'notes' => ['nullable', 'string', 'max:1000'],

            /** Whether it is offered on a new dispatch. Absent means yes. */
            'is_active' => ['sometimes', 'boolean'],

            /**
             * Whether a dispatch form opens on this company. At most one does, so switching it
             * on here switches it off wherever it was — and **absent means «اتركها كما هي»**
             * rather than «لا», so an edit that never mentioned it cannot move the shop's
             * default. A company being switched off loses it either way.
             */
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم شركة التوصيل مطلوب',
            'name.unique' => 'يوجد شركة توصيل بهذا الاسم',
            'name.min' => 'اسم شركة التوصيل قصير جداً',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'اسم شركة التوصيل',
            'phone' => 'رقم الهاتف',
            'notes' => 'ملاحظات',
            'is_active' => 'مفعّلة',
            'is_default' => 'الشركة الافتراضية',
        ];
    }
}
