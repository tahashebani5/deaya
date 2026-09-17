<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bags spoiled while producing one line of an order.
 *
 * `notes` is required, the same rule a stocktake adjustment carries and for the same reason:
 * spoilage explains nothing about itself, so the explanation is the point of the record.
 */
class RecordScrapLossRequest extends FormRequest
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
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],
            'notes' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.required' => 'الكمية مطلوبة',
            'quantity.numeric' => 'الكمية يجب أن تكون رقماً',
            'quantity.gt' => 'الكمية يجب أن تكون أكبر من صفر',
            'quantity.max' => 'الكمية أكبر من الحد المسموح',
            'notes.required' => 'سبب التلف مطلوب',
            'notes.min' => 'سبب التلف قصير جداً',
            'notes.max' => 'سبب التلف طويل جداً',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'quantity' => 'الكمية',
            'notes' => 'سبب التلف',
        ];
    }
}
