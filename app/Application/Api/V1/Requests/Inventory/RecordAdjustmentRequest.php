<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Inventory;

use App\Domain\Inventory\Enums\AdjustmentDirection;
use App\Domain\Inventory\Enums\StockAdjustmentReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A stocktake correction — the shelf disagreed with the book.
 *
 * One warehouse and a direction, rather than a from/to pair. Which end of the ledger row that
 * fills is the domain's business, not the caller's: a storekeeper counting a shelf should not
 * have to know that "found more" means filling `to_warehouse_id`.
 */
class RecordAdjustmentRequest extends FormRequest
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

            'warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->whereNull('deleted_at'),
            ],

            // Which way the count moved: `increase` found more than the book said, `decrease`
            // found less.
            'direction' => ['required', Rule::enum(AdjustmentDirection::class)],

            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],

            // Required exactly when the count found *more* than the book said — that is stock
            // opening a brand-new cost layer, and unlike an arrival (which may genuinely have no
            // recorded price yet) an adjustment has no natural cost signal of its own to fall back
            // on. A silent zero here would understate the cost of goods sold the first time this
            // stock is drawn down, with nothing in the record to explain why. Absent and ignored
            // on a Decrease, which only ever consumes layers that already exist.
            'unit_cost' => ['required_if:direction,increase', 'numeric', 'gte:0', 'max:999999999.999'],

            // The mirror of `unit_cost`, and required for the same kind of reason. A decrease
            // says stock left without being sold, and «نقص» alone is the question rather than
            // the answer — «كم هالك هذا الشهر؟» was unanswerable while the only record of it was
            // Arabic prose in `notes`. Refused outright on an increase: finding more than the
            // book said is not a loss and has no member of this vocabulary.
            //
            // `unit_change` is excluded from what a person may send. It exists so the discards
            // `SetStockItemUnit` posts can be told apart from stock somebody actually lost.
            'adjustment_reason' => [
                'required_if:direction,decrease',
                'prohibited_if:direction,increase',
                Rule::in(StockAdjustmentReason::recordableValues()),
            ],

            // **Required here alone.** The other three movements explain themselves — stock
            // arrived, moved, or went out on an order. An adjustment says the records were
            // wrong and offers no reason of its own, so the reason is the operation: breakage,
            // a miscount, waste. Without it a discrepancy is recorded and its cause is lost
            // exactly when someone comes looking for a pattern in them.
            'notes' => ['required', 'string', 'min:3', 'max:1000'],
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
            'warehouse_id.required' => 'المخزن مطلوب',
            'warehouse_id.exists' => 'المخزن غير موجود',
            'direction.required' => 'اتجاه التسوية مطلوب',
            'direction.enum' => 'اتجاه التسوية غير صحيح',
            'quantity.required' => 'الكمية مطلوبة',
            'quantity.numeric' => 'الكمية يجب أن تكون رقماً',
            'quantity.gt' => 'الكمية يجب أن تكون أكبر من صفر',
            'quantity.max' => 'الكمية أكبر من الحد المسموح',
            'unit_cost.required_if' => 'تكلفة الوحدة مطلوبة عند تسجيل زيادة',
            'unit_cost.numeric' => 'تكلفة الوحدة يجب أن تكون رقماً',
            'unit_cost.gte' => 'تكلفة الوحدة يجب ألا تكون سالبة',
            'unit_cost.max' => 'تكلفة الوحدة أكبر من الحد المسموح',
            'adjustment_reason.required_if' => 'نوع النقص مطلوب: هالك أم عجز أم فرق جرد',
            'adjustment_reason.prohibited_if' => 'الزيادة ليست نقصاً، فلا نوع لها',
            'adjustment_reason.in' => 'نوع النقص غير صحيح',
            'notes.required' => 'سبب التسوية مطلوب',
            'notes.min' => 'سبب التسوية قصير جداً',
            'notes.max' => 'سبب التسوية طويل جداً',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'stock_item_id' => 'المادة',
            'warehouse_id' => 'المخزن',
            'direction' => 'اتجاه التسوية',
            'quantity' => 'الكمية',
            'unit_cost' => 'تكلفة الوحدة',
            'adjustment_reason' => 'نوع النقص',
            'notes' => 'سبب التسوية',
        ];
    }
}
