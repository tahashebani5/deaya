<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Notification;

use App\Domain\Notification\Enums\DevicePlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A phone asking to be woken.
 */
class RegisterDeviceRequest extends FormRequest
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
            // FCM registration tokens have no documented maximum; 255 is the column and fits the
            // current format twice over.
            'token' => ['required', 'string', 'min:10', 'max:255'],

            // The device says which it is, because a token cannot be asked — and the push
            // payload's override blocks differ per platform.
            'platform' => ['required', Rule::in(DevicePlatform::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'رمز الجهاز مطلوب',
            'platform.required' => 'نوع الجهاز مطلوب',
            'platform.in' => 'نوع الجهاز غير صحيح',
        ];
    }

    public function platform(): DevicePlatform
    {
        return DevicePlatform::from((string) $this->validated('platform'));
    }

    public function token(): string
    {
        return (string) $this->validated('token');
    }
}
