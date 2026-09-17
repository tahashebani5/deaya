<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Order;

use App\Domain\Order\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Taking money from a customer.
 *
 * There is no `type` field here, and there is no route that would accept one: which kind of
 * ledger entry this becomes is decided by the endpoint that was called. A payload that could
 * name its own type could turn a collection into a refund.
 */
class StoreOrderPaymentRequest extends FormRequest
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
            // `gt:0`, not `min:0` — an entry of nothing is not an event, and both the domain and
            // a database CHECK hold the same line. The upper bound keeps a slipped keyboard out
            // of a decimal(12,2) column, where it would fail as a 500 instead of a 422.
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],

            'method' => ['required', Rule::enum(PaymentMethod::class)],

            'reference' => ['nullable', 'string', 'max:100'],

            // **When the money moved, not when this was typed.** Back-dating is ordinary — a
            // deposit taken on Thursday evening is entered on Saturday morning — so a past date
            // is accepted and the future is not: money that has not moved yet is not an entry.
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],

            'notes' => ['nullable', 'string', 'max:1000'],

            /*
             * **The receipt (الواصل), PDF or an image, and required for a transfer.**
             *
             * `required_if` names the one method that demands it, built from the enum case
             * rather than a loose string so renaming the value cannot leave this rule pointing
             * at nothing. `PaymentMethod::requiresReceipt()` states the same rule for the
             * domain, and a database CHECK states it a third time — this copy is the one that
             * produces the friendly field-level 422.
             *
             * The accepted shapes come from `media.payment_receipts`, which is the list the
             * app's contract test reads — a copy typed here would be the one that drifts.
             *
             * `mimetypes` reads the magic bytes with finfo; `mimes` is a second reading of the
             * same bytes kept because it is the rule whose message names an extension, which is
             * what a person needs to be told. Neither trusts the client's filename or its
             * Content-Type header.
             */
            'receipt' => [
                'required_if:method,'.PaymentMethod::BankTransfer->value,
                'nullable',
                'file',
                'mimetypes:'.implode(',', (array) config('media.payment_receipts.mimetypes')),
                'mimes:'.implode(',', (array) config('media.payment_receipts.mimes')),
                'max:'.config('media.payment_receipts.max_kilobytes'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'المبلغ مطلوب',
            'amount.numeric' => 'المبلغ يجب أن يكون رقماً',
            'amount.gt' => 'المبلغ يجب أن يكون أكبر من صفر',
            'amount.max' => 'المبلغ أكبر من الحد المسموح',
            'method.required' => 'طريقة الدفع مطلوبة',
            'method.enum' => 'طريقة الدفع غير معروفة',
            'reference.max' => 'رقم المرجع طويل جداً',
            'paid_at.date' => 'تاريخ الدفع غير صحيح',
            'paid_at.before_or_equal' => 'تاريخ الدفع لا يمكن أن يكون في المستقبل',
            'notes.max' => 'الملاحظات طويلة جداً',
            'receipt.required_if' => 'الدفع بحوالة يتطلب إرفاق الواصل',
            'receipt.file' => 'الواصل يجب أن يكون ملفاً',
            'receipt.mimetypes' => 'الواصل يجب أن يكون ملف PDF أو صورة',
            'receipt.mimes' => 'الواصل يجب أن يكون بصيغة PDF أو JPG أو PNG أو WEBP',
            'receipt.max' => 'حجم الواصل يجب ألا يتجاوز '.
                (int) (config('media.payment_receipts.max_kilobytes') / 1024).' ميجابايت',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount' => 'المبلغ',
            'method' => 'طريقة الدفع',
            'reference' => 'رقم المرجع',
            'paid_at' => 'تاريخ الدفع',
            'notes' => 'الملاحظات',
            'receipt' => 'الواصل',
        ];
    }
}
