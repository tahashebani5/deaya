<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stock arriving from a supplier.
 *
 * No source warehouse: it was not ours before. That is why this is its own endpoint rather than
 * a `movement_type` on a generic one — the field simply does not exist for this operation, and a
 * request that cannot express it is better than one that documents it as optional.
 */
class RecordArrivalRequest extends FormRequest
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

            'to_warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->whereNull('deleted_at'),
            ],

            // `gt:0`, not `min:0` — a movement of nothing explains nothing, and a database CHECK
            // holds the same rule. Whole numbers are enforced separately, per product: the
            // catalogue decides whether this size is countable or weighed.
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],

            // The purchase order this arrived against, once Orders lands. Unconstrained today.
            'reference_id' => ['nullable', 'integer', 'min:1'],

            // What this arrival cost, if known. Left blank for a shipment whose price was never
            // recorded — its cost layer opens at zero rather than the arrival being refused; see
            // ApplyStockChange::increase().
            'unit_cost' => ['nullable', 'numeric', 'gte:0', 'max:999999999.999'],

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
            'to_warehouse_id.required' => 'مخزن الاستلام مطلوب',
            'to_warehouse_id.exists' => 'مخزن الاستلام غير موجود',
            'quantity.required' => 'الكمية مطلوبة',
            'quantity.numeric' => 'الكمية يجب أن تكون رقماً',
            'quantity.gt' => 'الكمية يجب أن تكون أكبر من صفر',
            'quantity.max' => 'الكمية أكبر من الحد المسموح',
            'unit_cost.numeric' => 'تكلفة الوحدة يجب أن تكون رقماً',
            'unit_cost.gte' => 'تكلفة الوحدة يجب ألا تكون سالبة',
            'unit_cost.max' => 'تكلفة الوحدة أكبر من الحد المسموح',
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
            'to_warehouse_id' => 'مخزن الاستلام',
            'quantity' => 'الكمية',
            'reference_id' => 'رقم المرجع',
            'unit_cost' => 'تكلفة الوحدة',
            'notes' => 'الملاحظات',
        ];
    }
}
