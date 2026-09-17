<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Creating a staff account.
 *
 * The same shape as registration, because it produces the same row — deliberately a separate
 * request class rather than an alias of `RegisterRequest`, since the two differ in the one way
 * that matters: this one may hand the new account its roles, and registration must never be
 * able to. A shared parent would put `roles` one `sometimes` away from the public endpoint.
 */
class StoreUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:3', 'max:255'],
            // withoutTrashed on every uniqueness rule in this API: a deleted row keeps its
            // email, but the partial unique index no longer counts it, so validation must not
            // either — or the 422 would name an account the caller can neither see nor recover.
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')->withoutTrashed()],
            // Libyan mobile shape: 09 followed by 8 digits. Either identifier signs in, so both
            // are unique.
            'phone' => ['required', 'string', 'regex:/^09\d{8}$/', Rule::unique('users', 'phone')->withoutTrashed()],
            // Confirmed, even though an administrator is typing it for somebody else — *because*
            // they are. A mistyped password here locks out a colleague who cannot see what was
            // typed, and nobody finds out until their first shift.
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            // Optional: an account with no roles is a real and useful thing to create — it can
            // sign in and do nothing until somebody decides what its job is.
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['required', 'string', 'distinct', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم الموظف مطلوب',
            'name.min' => 'الاسم يجب أن يكون 3 أحرف على الأقل',
            'email.required' => 'البريد الإلكتروني مطلوب',
            'email.email' => 'البريد الإلكتروني غير صحيح',
            'email.unique' => 'البريد الإلكتروني مستخدم مسبقاً',
            'phone.required' => 'رقم الهاتف مطلوب',
            'phone.regex' => 'رقم الهاتف يجب أن يبدأ بـ 09 ويكون 10 أرقام',
            'phone.unique' => 'رقم الهاتف مستخدم مسبقاً',
            'password.required' => 'كلمة المرور مطلوبة',
            'password.min' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل',
            'password.confirmed' => 'تأكيد كلمة المرور غير مطابق',
            'roles.*.exists' => 'الدور المحدد غير موجود',
            'roles.*.distinct' => 'الدور مكرر في القائمة',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'الاسم',
            'email' => 'البريد الإلكتروني',
            'phone' => 'رقم الهاتف',
            'password' => 'كلمة المرور',
            'roles' => 'الأدوار',
        ];
    }

    /**
     * @return list<string>
     */
    public function roleNames(): array
    {
        return array_values(array_unique((array) ($this->validated('roles') ?? [])));
    }
}
