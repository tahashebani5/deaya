<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Forgiving what is left of an order's debt.
 *
 * **Two fields, and both are required.** The amount, because a write-off is rarely the whole
 * remainder — it is usually the five dinars that came back short — and the domain bounds it by
 * what is actually owed. The reason, because this is the one entry in the ledger that turns a
 * shortfall into a loss, and a loss with a blank beside it is the row an auditor stops at.
 *
 * **Nothing else is asked for.** No method: no money moved in either direction, and the table's
 * CHECK refuses a write-off that names one. No date: a payment may be back-dated because the
 * cash genuinely moved on Thursday, but a decision is taken when it is taken, and a
 * back-datable one is a way to move this month's loss into last month's books.
 *
 * The upper bound lives in the domain rather than here — see `WriteOffExceedsRemaining` — because
 * it is a fact about the order, not about the payload, and validation cannot see the order.
 */
class WriteOffOrderBalanceRequest extends FormRequest
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
            // The same shape the payment endpoint accepts, down to the ceiling: one money
            // field should not be stricter than another on the screen beside it.
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'المبلغ المراد شطبه مطلوب',
            'amount.numeric' => 'المبلغ يجب أن يكون رقماً',
            'amount.max' => 'المبلغ أكبر من الحد المسموح',
            'amount.gt' => 'المبلغ يجب أن يكون أكبر من صفر',
            'reason.required' => 'سبب شطب الفرق مطلوب',
            'reason.min' => 'السبب قصير جداً',
            'reason.max' => 'السبب طويل جداً',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount' => 'المبلغ',
            'reason' => 'السبب',
        ];
    }
}
