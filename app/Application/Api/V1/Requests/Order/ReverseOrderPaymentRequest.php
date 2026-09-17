<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancelling an entry that should never have been written.
 *
 * **One field, and it is required.** The amount is not asked for — a reversal always carries its
 * original's, because a partial undo is not an undo — and the method is not asked for either,
 * since no money moved.
 *
 * The reason is mandatory for the same reason cancelling an order is the one status change that
 * must justify itself: an action that takes money back off an order owes the next reader an
 * explanation, and «تم الإلغاء» with a blank beside it is not one.
 */
class ReverseOrderPaymentRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'سبب إلغاء الدفعة مطلوب',
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
            'reason' => 'السبب',
        ];
    }
}
