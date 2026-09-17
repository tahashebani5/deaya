<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Order;

use App\Domain\Order\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Handing money back to a customer.
 *
 * **The rules are written out in full rather than inherited from
 * {@see StoreOrderPaymentRequest}.** Scramble reads `rules()` without running it, so
 * `array_merge(parent::rules(), …)` publishes an endpoint with no request body at all — see
 * RULES.md §7. The duplication is the price of a correct spec, and it is charged deliberately.
 *
 * `notes` is where the reason goes, and it is optional here while a *reversal* demands one. The
 * two are not the same act: a refund is agreed with the customer and usually obvious from the
 * order's own state, while a reversal asserts that a colleague's entry never happened.
 */
class RefundOrderPaymentRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],

            // Required, like a payment's: cash back and a transfer back are different events to
            // whoever reconciles the drawer at the end of the day.
            'method' => ['required', Rule::enum(PaymentMethod::class)],

            'reference' => ['nullable', 'string', 'max:100'],

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
            'method.required' => 'طريقة الردّ مطلوبة',
            'method.enum' => 'طريقة الردّ غير معروفة',
            'reference.max' => 'رقم المرجع طويل جداً',
            'paid_at.date' => 'تاريخ الردّ غير صحيح',
            'paid_at.before_or_equal' => 'تاريخ الردّ لا يمكن أن يكون في المستقبل',
            'notes.max' => 'الملاحظات طويلة جداً',
            'receipt.required_if' => 'الردّ بحوالة يتطلب إرفاق الواصل',
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
            'method' => 'طريقة الردّ',
            'reference' => 'رقم المرجع',
            'paid_at' => 'تاريخ الردّ',
            'notes' => 'الملاحظات',
            'receipt' => 'الواصل',
        ];
    }
}
