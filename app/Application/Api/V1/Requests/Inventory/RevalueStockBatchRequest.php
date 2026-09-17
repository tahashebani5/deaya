<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Changing what a quantity of stock is carried at.
 *
 * **`quantity` is optional and is not checked against the layer here.** Omitting it means "all of
 * what is left", which is the common case. Naming a smaller figure splits the layer — and how
 * much is left is a number that moves: an order going to print between this request being sent
 * and the row being locked changes it. So the comparison belongs under the lock, as a business
 * refusal with both numbers in the sentence, not as a validation rule that was true a moment
 * ago. See `RevaluationExceedsRemaining`.
 *
 * **`reason` is required**, exactly as it is on a stocktake correction and for the same reason:
 * this changes the books with no physical event behind it, and a figure nobody can account for
 * is what the field exists to prevent.
 */
class RevalueStockBatchRequest extends FormRequest
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
            // `gte:0`, not `gt:0`: zero is a real answer here. Correcting a layer *down* to
            // nothing — stock that turned out to be a sample, or a supplier's freebie — is a
            // decision somebody may need to record, and the column's CHECK allows it.
            'unit_cost' => ['required', 'numeric', 'gte:0', 'max:999999999.999'],

            'quantity' => ['nullable', 'numeric', 'gt:0', 'max:999999999.999'],

            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unit_cost.required' => 'تكلفة الوحدة مطلوبة',
            'unit_cost.numeric' => 'تكلفة الوحدة يجب أن تكون رقماً',
            'unit_cost.gte' => 'تكلفة الوحدة يجب ألا تكون سالبة',
            'unit_cost.max' => 'تكلفة الوحدة أكبر من الحد المسموح',
            'quantity.numeric' => 'الكمية يجب أن تكون رقماً',
            'quantity.gt' => 'الكمية يجب أن تكون أكبر من صفر',
            'reason.required' => 'سبب تعديل التكلفة مطلوب',
            'reason.min' => 'سبب تعديل التكلفة قصير جداً',
            'reason.max' => 'سبب تعديل التكلفة طويل جداً',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'unit_cost' => 'تكلفة الوحدة',
            'quantity' => 'الكمية',
            'reason' => 'سبب تعديل التكلفة',
        ];
    }
}
