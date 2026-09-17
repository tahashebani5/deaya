<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\PurchaseOrder;

use App\Domain\PurchaseOrder\Actions\UpdatePurchaseOrder;
use Illuminate\Validation\Rule;

/**
 * Same shape as creating, plus an optional `id` on each line so existing lines can be told from
 * new ones — see {@see UpdatePurchaseOrder}.
 *
 * Written out in full rather than merged onto the parent's rules — Scramble reads this method
 * statically to build the OpenAPI request body and cannot follow an
 * `array_merge(parent::rules(), …)` call, the same trap `UpdateVendorRequest` and
 * `UpdateWarehouseRequest` avoid. The domain itself is what actually refuses this request once
 * the order has left `new` — see `PurchaseOrderNotEditable` — so there is nothing state-specific
 * to validate here.
 */
class UpdatePurchaseOrderRequest extends StorePurchaseOrderRequest
{
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

            // Present on a line that already exists; absent on one being added. Any existing
            // line whose id is missing from this set is removed — see UpdatePurchaseOrder.
            'items.*.id' => ['nullable', 'integer'],

            'items.*.stock_item_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('stock_items', 'id')->whereNull('deleted_at'),
            ],

            'items.*.quantity_ordered' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],

            'items.*.base_total_cost' => ['required', 'numeric', 'gte:0', 'max:999999999999.99'],

            // Same "send the whole current set every time" contract as items — see
            // UpdatePurchaseOrder::syncAdditionalCosts(). Present on a cost that already exists;
            // absent on one being added. Any existing cost missing from this set is removed.
            'additional_costs' => ['nullable', 'array'],
            'additional_costs.*.id' => ['nullable', 'integer'],
            'additional_costs.*.name' => ['required', 'string', 'max:255'],
            'additional_costs.*.amount' => ['required', 'numeric', 'gte:0', 'max:999999999999.99'],
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
            'items.*.id' => 'معرف البند',
            'items.*.stock_item_id' => 'المادة',
            'items.*.quantity_ordered' => 'الكمية المطلوبة',
            'items.*.base_total_cost' => 'التكلفة الإجمالية للبند',
            'additional_costs' => 'التكاليف الإضافية',
            'additional_costs.*.id' => 'معرف التكلفة الإضافية',
            'additional_costs.*.name' => 'اسم التكلفة الإضافية',
            'additional_costs.*.amount' => 'قيمة التكلفة الإضافية',
        ];
    }
}
