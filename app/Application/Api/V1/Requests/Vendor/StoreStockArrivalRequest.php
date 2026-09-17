<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A shipment received from a vendor: one document, one warehouse, one or more lines.
 *
 * There is no update request beside this one — a posted arrival is never edited, the same rule
 * every stock-movement request already follows.
 */
class StoreStockArrivalRequest extends FormRequest
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

            // The vendor's own document number. Optional: some shipments arrive without one.
            'invoice_number' => ['nullable', 'string', 'max:100'],

            'notes' => ['nullable', 'string', 'max:1000'],

            'items' => ['required', 'array', 'min:1'],

            'items.*.stock_item_id' => [
                'required', 'integer',
                Rule::exists('stock_items', 'id')->whereNull('deleted_at'),
            ],

            // `gt:0`, not `min:0` — a line of nothing explains nothing, and a database CHECK
            // holds the same rule. Whole numbers are enforced separately, per product, when the
            // line is posted to the ledger.
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],
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
            'warehouse_id.required' => 'مخزن الاستلام مطلوب',
            'warehouse_id.exists' => 'مخزن الاستلام غير موجود',
            'invoice_number.max' => 'رقم الفاتورة طويل جداً',
            'notes.max' => 'الملاحظات طويلة جداً',
            'items.required' => 'يجب إضافة بند واحد على الأقل',
            'items.min' => 'يجب إضافة بند واحد على الأقل',
            'items.*.stock_item_id.required' => 'المادة مطلوبة',
            'items.*.stock_item_id.exists' => 'المادة المحددة غير موجودة',
            'items.*.quantity.required' => 'الكمية مطلوبة',
            'items.*.quantity.numeric' => 'الكمية يجب أن تكون رقماً',
            'items.*.quantity.gt' => 'الكمية يجب أن تكون أكبر من صفر',
            'items.*.quantity.max' => 'الكمية أكبر من الحد المسموح',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'vendor_id' => 'المورد',
            'warehouse_id' => 'مخزن الاستلام',
            'invoice_number' => 'رقم الفاتورة',
            'notes' => 'الملاحظات',
            'items' => 'البنود',
            'items.*.stock_item_id' => 'المادة',
            'items.*.quantity' => 'الكمية',
        ];
    }
}
