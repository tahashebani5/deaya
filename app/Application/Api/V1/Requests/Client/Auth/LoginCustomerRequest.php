<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Client\Auth;

use App\Domain\Customer\Exceptions\CustomerCredentialsAreWrong;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Signing in to the customer app.
 *
 * **`phone` is validated for presence and shape only — never for existence.** A `exists` rule
 * here would answer «رقم الهاتف غير مسجَّل» with a 422 before any password was checked, which is
 * precisely the account enumeration {@see CustomerCredentialsAreWrong}
 * exists to prevent. Whether the number belongs to anybody is the domain's question, and it
 * answers it with one message for every kind of miss.
 */
class LoginCustomerRequest extends FormRequest
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
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'رقم الهاتف مطلوب',
            'password.required' => 'كلمة المرور مطلوبة',
        ];
    }
}
