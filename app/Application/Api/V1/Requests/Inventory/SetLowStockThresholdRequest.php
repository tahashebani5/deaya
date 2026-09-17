<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The only field on a balance row a person may write.
 *
 * `quantity` is deliberately absent and has no request of its own anywhere in this API — a
 * balance moves by recording a movement that explains it. There is no shape of payload that can
 * set a shelf count directly.
 */
class SetLowStockThresholdRequest extends FormRequest
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
            // Present but null clears the warning. That is not the same as 0, which means "warn
            // me when this reaches nothing" — a real thing to ask for. `present` rather than
            // `sometimes`, so clearing it is an explicit act rather than an omission.
            'low_stock_threshold' => ['present', 'nullable', 'numeric', 'min:0', 'max:999999999.999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'low_stock_threshold.present' => 'حد التنبيه مطلوب — أرسل قيمة فارغة لإلغاء التنبيه',
            'low_stock_threshold.numeric' => 'حد التنبيه يجب أن يكون رقماً',
            'low_stock_threshold.min' => 'حد التنبيه لا يمكن أن يكون سالباً',
            'low_stock_threshold.max' => 'حد التنبيه أكبر من الحد المسموح',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'low_stock_threshold' => 'حد التنبيه',
        ];
    }
}
