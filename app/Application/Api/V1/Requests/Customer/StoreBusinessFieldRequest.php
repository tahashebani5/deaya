<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusinessFieldRequest extends FormRequest
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
            // `withoutTrashed` matches the partial unique index in the database: a field that
            // was deleted releases its name, and the rule has to agree with the constraint or
            // one of them produces a 500 where the other produces a message.
            'name' => [
                'required', 'string', 'min:2', 'max:100',
                Rule::unique('business_fields', 'name')->withoutTrashed(),
            ],

            // Optional because a field is offered the moment it is created; hiding one is a
            // later decision, taken from the list.
            'is_active' => ['sometimes', 'boolean'],

            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم مجال العمل مطلوب',
            'name.min' => 'اسم مجال العمل قصير جداً',
            'name.max' => 'اسم مجال العمل طويل جداً',
            'name.unique' => 'مجال العمل مسجّل مسبقاً',
            'is_active.boolean' => 'الحالة يجب أن تكون صحيحة أو خاطئة',
            'sort_order.integer' => 'الترتيب يجب أن يكون رقماً صحيحاً',
            'sort_order.min' => 'الترتيب لا يمكن أن يكون سالباً',
            'sort_order.max' => 'الترتيب أكبر من الحد المسموح',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'اسم مجال العمل',
            'is_active' => 'الحالة',
            'sort_order' => 'الترتيب',
        ];
    }
}
