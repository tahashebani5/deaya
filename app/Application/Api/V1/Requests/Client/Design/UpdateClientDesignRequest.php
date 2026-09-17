<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Client\Design;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Renaming a design from the app.
 *
 * `label` and nothing else. The file cannot be swapped — an order points at a design, so
 * replacing the bytes under a stable id would change what an old order says was printed — and
 * `notes` belongs to staff. So one field is the whole of it.
 */
class UpdateClientDesignRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label.required' => 'اسم التصميم مطلوب',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['label' => 'اسم التصميم'];
    }
}
