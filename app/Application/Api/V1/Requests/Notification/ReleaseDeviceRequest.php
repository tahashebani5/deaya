<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A phone being forgotten — on sign-out, and when notifications are switched off.
 *
 * **The sign-out call is the one that matters.** Skip it and a shared shop phone keeps receiving
 * the previous employee's notifications until FCM happens to rotate the token, which may be
 * never.
 */
class ReleaseDeviceRequest extends FormRequest
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
            'token' => ['required', 'string', 'min:10', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'رمز الجهاز مطلوب',
        ];
    }

    public function token(): string
    {
        return (string) $this->validated('token');
    }
}
