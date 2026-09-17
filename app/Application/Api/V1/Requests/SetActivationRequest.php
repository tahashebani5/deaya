<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Activating or deactivating a record.
 *
 * Shared across contexts because the payload genuinely is the same one flag — a Customer-named
 * request used by Products would tie two contexts together for no reason.
 */
class SetActivationRequest extends FormRequest
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
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'is_active.required' => 'الحالة مطلوبة',
            'is_active.boolean' => 'الحالة يجب أن تكون صحيحة أو خاطئة',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['is_active' => 'الحالة'];
    }
}
