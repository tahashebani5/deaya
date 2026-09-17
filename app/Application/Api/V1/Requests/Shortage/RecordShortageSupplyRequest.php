<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Shortage;

use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Shortage\Exceptions\SupplyExceedsRemaining;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording that some of a shortage came back.
 *
 * **The three required fields are the three the brief names** — «الكمية + القيمة + طريقة الدفع» —
 * and all three are required here, in the DTO's types, and a third time as a CHECK on the table.
 * Three layers because each catches a different caller: the form produces the readable 422, the
 * types stop a console command, and the constraint is the actual guarantee. RULES §8.
 *
 * **The ceiling is not checked here.** Whether the quantity fits in what is left is a question
 * about the shortage's own ledger under a lock, and asking it in validation would be a read
 * outside the transaction that the write then races — see {@see SupplyExceedsRemaining} and
 * `RecordShortageSupply`, which takes the lock first and answers it there.
 *
 * The payment method is chosen from what the system already knows, never typed: `PaymentMethod`
 * is the same vocabulary a customer's payment uses.
 *
 * **And `warehouse_id` is where the sacks went.** A supply is the goods arriving, not a note
 * beside them — see `RecordShortageSupply` — so anything the warehouse can hold must say which
 * shelf, and anything it cannot must not.
 */
class RecordShortageSupplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // «اليوم» is the answer nine times out of ten, and a required date on a counter form is a
        // field somebody types wrongly in a hurry. Defaulted rather than made optional so the
        // column is never null and every report can read it without a fallback.
        if (! $this->has('occurred_on')) {
            $this->merge(['occurred_on' => now()->toDateString()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'method' => ['required', Rule::in(PaymentMethod::values())],
            'reference' => ['nullable', 'string', 'max:100'],
            // Not in the future: goods that have not been bought yet are not a supply, and a
            // fat-fingered year would sit at the top of every ledger forever.
            'occurred_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],

            /*
             * **الواصل — optional on every method, and that is deliberate.**
             *
             * `order_payments` demands one for a حوالة, because a transfer to a customer is
             * proved by the paper they send us. Here the paper is whatever the shop next door
             * wrote, and often there is none at all: refusing the entry for want of a document
             * would push the purchase back onto paper, which is the thing this feature exists to
             * end. What arrives is kept; what does not is not asked for.
             *
             * The accepted shapes are `media.payment_receipts`' — the same list the app is told,
             * so a file it pre-checked is never refused for a rule it could not see.
             */
            'receipt' => [
                'nullable',
                'file',
                'mimetypes:'.implode(',', (array) config('media.payment_receipts.mimetypes')),
                'mimes:'.implode(',', (array) config('media.payment_receipts.mimes')),
                'max:'.config('media.payment_receipts.max_kilobytes'),
            ],

            /*
             * Where the goods landed.
             *
             * **Only checked for existence here.** Whether this shortage needs a warehouse at all
             * is a fact about the shortage rather than about the payload — a size has a shelf, a
             * roll of tape does not — and the domain answers it with its own two sentences
             * (`SupplyNeedsAWarehouse`, `ShortageIsNotStockable`) rather than a `required_if` that
             * would have to re-derive `Shortage::isStockable()` in a second place.
             */
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.required' => 'الكمية التي تم توفيرها مطلوبة',
            'quantity.gt' => 'الكمية يجب أن تكون أكبر من صفر',
            'quantity.decimal' => 'الكمية تقبل ثلاث خانات عشرية على الأكثر',
            'amount.required' => 'القيمة المدفوعة مطلوبة',
            'amount.gt' => 'القيمة يجب أن تكون أكبر من صفر',
            'amount.decimal' => 'القيمة تقبل خانتين عشريتين على الأكثر',
            'method.required' => 'طريقة الدفع مطلوبة',
            'method.in' => 'طريقة الدفع غير معروفة',
            'occurred_on.before_or_equal' => 'لا يمكن تسجيل عملية بتاريخ مستقبلي',
            'warehouse_id.exists' => 'المخزن غير موجود',
            'receipt.file' => 'الواصل يجب أن يكون ملفاً',
            'receipt.mimetypes' => 'الواصل يجب أن يكون ملف PDF أو صورة',
            'receipt.mimes' => 'الواصل يجب أن يكون بصيغة PDF أو JPG أو PNG أو WEBP',
            'receipt.max' => 'حجم الواصل أكبر من المسموح',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'quantity' => 'الكمية',
            'amount' => 'القيمة',
            'method' => 'طريقة الدفع',
            'reference' => 'رقم العملية',
            'occurred_on' => 'تاريخ التوفير',
            'notes' => 'ملاحظات',
            'warehouse_id' => 'المخزن',
            'receipt' => 'الواصل',
        ];
    }
}
