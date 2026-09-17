<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * An administrator setting a new password for a colleague who has forgotten theirs.
 *
 * **The current password is not asked for**, and cannot be: the person typing is not the
 * account holder and does not know it. What guards this endpoint is the `users.password` gate —
 * the administrator alone, and not by any permission that could be ticked onto a role.
 *
 * **The confirmation is asked for anyway, and for a sharper reason than usual.** A mistyped
 * password on a form somebody is filling for themselves is discovered a second later; mistyped
 * here it locks out a colleague who never saw what was typed, and nobody finds out until their
 * next shift.
 */
class SetUserPasswordRequest extends FormRequest
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
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'كلمة المرور مطلوبة',
            'password.min' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل',
            'password.confirmed' => 'تأكيد كلمة المرور غير مطابق',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['password' => 'كلمة المرور'];
    }
}
