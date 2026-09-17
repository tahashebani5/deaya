<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Renaming a design, and nothing else.
 *
 * **There is no endpoint that swaps the file.** A design is what an order points at, so
 * replacing the bytes under a stable id would silently change what an old order says was
 * printed. A new version is a new upload.
 */
class UpdateCustomerDesignRequest extends FormRequest
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
        // Written out rather than borrowed from the store request: Scramble analyses this
        // method statically, and anything it cannot follow publishes an endpoint with no
        // request body at all.
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['label' => 'اسم التصميم', 'notes' => 'ملاحظات'];
    }
}
