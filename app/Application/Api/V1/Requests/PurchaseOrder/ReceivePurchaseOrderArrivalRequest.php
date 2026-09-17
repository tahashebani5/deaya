<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Receiving a shipment against a purchase order.
 *
 * Deliberately the same shape as `StoreStockArrivalRequest`'s items, minus `vendor_id` and
 * `warehouse_id` — both are already fixed by the order itself, so re-sending them would only
 * invite a payload that disagrees with the order it names.
 *
 * Whether *this* order may currently receive anything, and whether the line was ordered at all,
 * are domain questions — see `PurchaseOrderNotReceivable` and `StockItemNotOnPurchaseOrder` —
 * because both depend on state this request cannot see without reading the database twice. A
 * quantity larger than what remains on order is *not* one of them: a supplier who overships is
 * booked in whole, surplus included.
 */
class ReceivePurchaseOrderArrivalRequest extends FormRequest
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
            // The vendor's own document number. Optional: some shipments arrive without one.
            'invoice_number' => ['nullable', 'string', 'max:100'],

            'notes' => ['nullable', 'string', 'max:1000'],

            'items' => ['required', 'array', 'min:1'],

            'items.*.stock_item_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('stock_items', 'id')->whereNull('deleted_at'),
            ],

            // `gt:0`, not `min:0` — a line of nothing is not a delivery, the same rule
            // StoreStockArrivalRequest holds. Whole numbers are enforced separately, per
            // product, when the line is posted to the ledger.
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'invoice_number.max' => 'رقم الفاتورة طويل جداً',
            'notes.max' => 'الملاحظات طويلة جداً',
            'items.required' => 'يجب إضافة بند واحد على الأقل',
            'items.min' => 'يجب إضافة بند واحد على الأقل',
            'items.*.stock_item_id.required' => 'المادة مطلوبة',
            'items.*.stock_item_id.exists' => 'المادة المحددة غير موجودة',
            'items.*.stock_item_id.distinct' => 'لا يمكن تكرار نفس المادة أكثر من مرة في الشحنة',
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
            'invoice_number' => 'رقم الفاتورة',
            'notes' => 'الملاحظات',
            'items' => 'البنود',
            'items.*.stock_item_id' => 'المادة',
            'items.*.quantity' => 'الكمية',
        ];
    }
}
