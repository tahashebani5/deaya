<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Putting the next version of the artwork in front of the customer.
 *
 * The file is never uploaded here — it is chosen from the customer's library, which is where
 * their artwork lives. Whether the chosen design is *theirs* is a domain refusal with a sentence
 * worth reading, not a validation rule.
 */
class StoreOrderDesignRequest extends FormRequest
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
            'customer_design_id' => ['required', 'integer', Rule::exists('customer_designs', 'id')->withoutTrashed()],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_design_id.required' => 'التصميم مطلوب',
            'customer_design_id.exists' => 'التصميم غير موجود',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'customer_design_id' => 'التصميم',
            'notes' => 'ملاحظات',
        ];
    }
}
