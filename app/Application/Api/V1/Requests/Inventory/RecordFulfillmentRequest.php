<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stock leaving for a customer's order.
 *
 * No destination warehouse: it stops being ours. `reference_id` is the order it went out on —
 * nullable today because `orders` does not exist yet, and it will become required in the same
 * change that adds the foreign key.
 */
class RecordFulfillmentRequest extends FormRequest
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
            'stock_item_id' => [
                'required', 'integer',
                Rule::exists('stock_items', 'id')->whereNull('deleted_at'),
            ],

            'from_warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->whereNull('deleted_at'),
            ],

            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],

            // 🎯 Becomes `required` and gains an `exists:orders,id` rule when Orders lands.
            'reference_id' => ['nullable', 'integer', 'min:1'],

            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stock_item_id.required' => 'المادة مطلوبة',
            'stock_item_id.exists' => 'المادة المحددة غير موجودة',
            'from_warehouse_id.required' => 'مخزن الصرف مطلوب',
            'from_warehouse_id.exists' => 'مخزن الصرف غير موجود',
            'quantity.required' => 'الكمية مطلوبة',
            'quantity.numeric' => 'الكمية يجب أن تكون رقماً',
            'quantity.gt' => 'الكمية يجب أن تكون أكبر من صفر',
            'quantity.max' => 'الكمية أكبر من الحد المسموح',
            'notes.max' => 'الملاحظات طويلة جداً',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'stock_item_id' => 'المادة',
            'from_warehouse_id' => 'مخزن الصرف',
            'quantity' => 'الكمية',
            'reference_id' => 'رقم الطلب',
            'notes' => 'الملاحظات',
        ];
    }
}
