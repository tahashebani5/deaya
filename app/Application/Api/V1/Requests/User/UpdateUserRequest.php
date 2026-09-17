<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correcting an employee's details — a wrong number, a married name, a mistyped email.
 *
 * **No `password` and no `salary`, and their absence is the guard rather than an omission.**
 * This endpoint is reached with `users.manage`; a password belongs to the administrator alone
 * and a salary to `users.salary`. A `sometimes` rule for either here would hand both powers to
 * the weakest of the three permissions. `roles` is likewise absent — they have their own
 * endpoint, and it replaces the whole set rather than merging.
 */
class UpdateUserRequest extends FormRequest
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
        // The employee being edited, resolved from the route. `ignore` on both uniqueness
        // rules: without it, saving a form whose email nobody touched would fail against the
        // row it belongs to.
        $userId = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'min:3', 'max:255'],
            // withoutTrashed, like every uniqueness rule in this API: a deleted row keeps its
            // email but the partial unique index no longer counts it, so validation must not
            // either — or the 422 names an account the caller can neither see nor recover.
            'email' => [
                'required', 'string', 'email:rfc', 'max:255',
                Rule::unique('users', 'email')->ignore($userId)->withoutTrashed(),
            ],
            // Libyan mobile shape: 09 followed by 8 digits. Either identifier signs in, so both
            // stay unique.
            'phone' => [
                'required', 'string', 'regex:/^09\d{8}$/',
                Rule::unique('users', 'phone')->ignore($userId)->withoutTrashed(),
            ],
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
        ];
    }
}
