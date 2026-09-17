<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
            // One field accepts either identifier — email address or phone number.
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            // Labels the issued token so a user can see and revoke individual devices.
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'login.required' => 'البريد الإلكتروني أو رقم الهاتف مطلوب',
            'password.required' => 'كلمة المرور مطلوبة',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'login' => 'البريد الإلكتروني أو رقم الهاتف',
            'password' => 'كلمة المرور',
            'device_name' => 'اسم الجهاز',
        ];
    }
}
