<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
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
            'vendor_id' => [
                'required', 'integer',
                Rule::exists('vendors', 'id')->whereNull('deleted_at'),
            ],

            'warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->whereNull('deleted_at'),
            ],

            'order_date' => ['required', 'date'],

            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],

            'notes' => ['nullable', 'string', 'max:1000'],

            'items' => ['required', 'array', 'min:1'],

            'items.*.stock_item_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('stock_items', 'id')->whereNull('deleted_at'),
            ],

            // `gt:0`, not `min:0` — a line ordering nothing explains nothing, the same rule
            // stock-arrival lines hold. Whole-number sizes are enforced only when stock is
            // actually posted against this line, by ReceivePurchaseOrder — an order is paperwork,
            // not a movement, and may plan for a quantity that later arrives in whole pieces.
            'items.*.quantity_ordered' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],

            // `gte:0`, not `gt:0` — a free replacement from the vendor is a real cost of zero,
            // not an omission. There is no catalogue price to fall back on for what *we* pay a
            // vendor, so this is always required. The server derives the per-unit figure from
            // this — see PurchaseOrderItemData — never the other way around.
            'items.*.base_total_cost' => ['required', 'numeric', 'gte:0', 'max:999999999999.99'],

            // Optional order-level costs (delivery, unloading, customs, ...) distributed across
            // the lines above — see AllocatePurchaseOrderAdditionalCosts. Absent or empty means
            // there are none.
            'additional_costs' => ['nullable', 'array'],
            'additional_costs.*.name' => ['required', 'string', 'max:255'],
            'additional_costs.*.amount' => ['required', 'numeric', 'gte:0', 'max:999999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vendor_id.required' => 'المورد مطلوب',
            'vendor_id.exists' => 'المورد المحدد غير موجود',
            'warehouse_id.required' => 'مخزن الوجهة مطلوب',
            'warehouse_id.exists' => 'مخزن الوجهة غير موجود',
            'order_date.required' => 'تاريخ الطلب مطلوب',
            'order_date.date' => 'تاريخ الطلب غير صحيح',
            'expected_date.date' => 'التاريخ المتوقع غير صحيح',
            'expected_date.after_or_equal' => 'التاريخ المتوقع يجب أن يكون بعد أو يساوي تاريخ الطلب',
            'notes.max' => 'الملاحظات طويلة جداً',
            'items.required' => 'يجب إضافة بند واحد على الأقل',
            'items.min' => 'يجب إضافة بند واحد على الأقل',
            'items.*.stock_item_id.required' => 'المادة مطلوبة',
            'items.*.stock_item_id.exists' => 'المادة المحددة غير موجودة',
            'items.*.stock_item_id.distinct' => 'لا يمكن تكرار نفس المادة أكثر من مرة في أمر الشراء',
            'items.*.quantity_ordered.required' => 'الكمية المطلوبة مطلوبة',
            'items.*.quantity_ordered.numeric' => 'الكمية المطلوبة يجب أن تكون رقماً',
            'items.*.quantity_ordered.gt' => 'الكمية المطلوبة يجب أن تكون أكبر من صفر',
            'items.*.quantity_ordered.max' => 'الكمية المطلوبة أكبر من الحد المسموح',
            'items.*.base_total_cost.required' => 'التكلفة الإجمالية للبند مطلوبة',
            'items.*.base_total_cost.numeric' => 'التكلفة الإجمالية للبند يجب أن تكون رقماً',
            'items.*.base_total_cost.gte' => 'التكلفة الإجمالية للبند لا يمكن أن تكون سالبة',
            'items.*.base_total_cost.max' => 'التكلفة الإجمالية للبند أكبر من الحد المسموح',
            'additional_costs.*.name.required' => 'اسم التكلفة الإضافية مطلوب',
            'additional_costs.*.name.max' => 'اسم التكلفة الإضافية طويل جداً',
            'additional_costs.*.amount.required' => 'قيمة التكلفة الإضافية مطلوبة',
            'additional_costs.*.amount.numeric' => 'قيمة التكلفة الإضافية يجب أن تكون رقماً',
            'additional_costs.*.amount.gte' => 'قيمة التكلفة الإضافية لا يمكن أن تكون سالبة',
            'additional_costs.*.amount.max' => 'قيمة التكلفة الإضافية أكبر من الحد المسموح',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'vendor_id' => 'المورد',
            'warehouse_id' => 'مخزن الوجهة',
            'order_date' => 'تاريخ الطلب',
            'expected_date' => 'التاريخ المتوقع',
            'notes' => 'الملاحظات',
            'items' => 'البنود',
            'items.*.stock_item_id' => 'المادة',
            'items.*.quantity_ordered' => 'الكمية المطلوبة',
            'items.*.base_total_cost' => 'التكلفة الإجمالية للبند',
            'additional_costs' => 'التكاليف الإضافية',
            'additional_costs.*.name' => 'اسم التكلفة الإضافية',
            'additional_costs.*.amount' => 'قيمة التكلفة الإضافية',
        ];
    }
}
