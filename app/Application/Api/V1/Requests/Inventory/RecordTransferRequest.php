<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Inventory;

use App\Domain\Inventory\Actions\RecordStockMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stock moving between two of our own warehouses.
 *
 * The only movement with both ends filled, and the only one that can deadlock — see
 * {@see RecordStockMovement::moveBalances()} for the lock ordering
 * that stops it.
 */
class RecordTransferRequest extends FormRequest
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

            // `different`, so the readable 422 arrives before the domain gets a chance to throw.
            // The rule is stated in three places on purpose — here, in the action, and as a
            // database CHECK — because each guards a different way in, and a transfer to itself
            // writes a ledger row claiming a move that never happened.
            'to_warehouse_id' => [
                'required', 'integer', 'different:from_warehouse_id',
                Rule::exists('warehouses', 'id')->whereNull('deleted_at'),
            ],

            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],

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
            'from_warehouse_id.required' => 'المخزن المصدر مطلوب',
            'from_warehouse_id.exists' => 'المخزن المصدر غير موجود',
            'to_warehouse_id.required' => 'مخزن الوجهة مطلوب',
            'to_warehouse_id.exists' => 'مخزن الوجهة غير موجود',
            'to_warehouse_id.different' => 'لا يمكن التحويل من المخزن إلى نفسه',
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
            'from_warehouse_id' => 'المخزن المصدر',
            'to_warehouse_id' => 'مخزن الوجهة',
            'quantity' => 'الكمية',
            'reference_id' => 'رقم المرجع',
            'notes' => 'الملاحظات',
        ];
    }
}
