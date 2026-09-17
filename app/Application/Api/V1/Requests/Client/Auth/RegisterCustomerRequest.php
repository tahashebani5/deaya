<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Client\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Signing up from the customer app.
 *
 * **Three fields, and no email.** That absence is the decision, not an omission: requiring one
 * would exclude the shopkeeper who has only a phone, and it is the single reason this account
 * does not live in `users`. See Docs/customer-app/CUSTOMER-APP-DESIGN.md §٢.
 *
 * `code` and `is_active` are not accepted here and never will be — the code comes from the
 * sequence and an account starts active. A payload carrying either is ignored rather than
 * refused, because the model's fillable list is what stops it and a client that sends a stray
 * key should not be told which keys exist.
 */
class RegisterCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            // The same shape `StoreCustomerRequest` accepts from staff — one rule for one column,
            // so a number a clerk could type is a number a customer can register with.
            // `withoutTrashed` because a removed customer must not hold his number hostage.
            'phone' => [
                'required', 'string', 'regex:/^\d{9,15}$/',
                Rule::unique('customers', 'phone')->withoutTrashed(),
            ],

            'password' => ['required', 'string', 'min:8', 'confirmed'],

            // What the token is labelled with in «الأجهزة». Optional: the service names it
            // `client` when the app does not say.
            'device_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'الاسم مطلوب',
            'phone.required' => 'رقم الهاتف مطلوب',
            'phone.regex' => 'رقم الهاتف يجب أن يكون أرقاماً فقط (من 9 إلى 15 رقماً)',
            'phone.unique' => 'رقم الهاتف مسجَّل مسبقاً. سجّل دخولك بدل إنشاء حساب',
            'password.required' => 'كلمة المرور مطلوبة',
            'password.min' => 'كلمة المرور يجب ألا تقل عن 8 خانات',
            'password.confirmed' => 'تأكيد كلمة المرور لا يطابقها',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'الاسم',
            'phone' => 'رقم الهاتف',
            'password' => 'كلمة المرور',
        ];
    }
}
