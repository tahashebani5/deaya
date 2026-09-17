<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVendorRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:255'],

            'contact_person' => ['nullable', 'string', 'max:255'],

            // One number identifies exactly one vendor, so it is unique here *and* in the
            // database — validation gives a readable 422, the unique index is what actually
            // holds under concurrent requests.
            'phone' => ['required', 'string', 'regex:/^\d{9,15}$/', Rule::unique('vendors', 'phone')->withoutTrashed()],

            'email' => ['nullable', 'email', 'max:255'],

            'address' => ['nullable', 'string', 'max:255'],

            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم المورد مطلوب',
            'name.min' => 'اسم المورد يجب أن يكون حرفين على الأقل',
            'name.max' => 'اسم المورد طويل جداً',
            'contact_person.max' => 'اسم المسؤول طويل جداً',
            'phone.required' => 'رقم الهاتف مطلوب',
            'phone.regex' => 'رقم الهاتف يجب أن يكون أرقاماً فقط (من 9 إلى 15 رقماً)',
            'phone.unique' => 'رقم الهاتف مستخدم مسبقاً لمورد آخر',
            'email.email' => 'البريد الإلكتروني غير صحيح',
            'address.max' => 'العنوان طويل جداً',
            'is_active.boolean' => 'حالة التنشيط يجب أن تكون صحيحة أو خاطئة',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'اسم المورد',
            'contact_person' => 'اسم المسؤول',
            'phone' => 'رقم الهاتف',
            'email' => 'البريد الإلكتروني',
            'address' => 'العنوان',
            'is_active' => 'الحالة',
        ];
    }
}
