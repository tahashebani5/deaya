<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Investor;

use App\Domain\Investor\Enums\WalletEntryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One movement of money a person is recording.
 *
 * **Only four types are offered.** An earning is written by the order that produced it and a
 * release by closing a deal; putting either on a form would be offering somebody the chance to
 * invent an earning, and the deal screen would then disagree with the orders behind it.
 *
 * `investor_deal_id` is required for the one type that names a deal and refused for the three
 * that do not, which mirrors the shape constraint the database itself enforces.
 */
class StoreWalletEntryRequest extends FormRequest
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
            'type' => ['required', Rule::in(WalletEntryType::recordableValues())],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],

            'investor_deal_id' => [
                Rule::requiredIf(fn () => $this->input('type') === WalletEntryType::Allocation->value),
                Rule::prohibitedIf(fn () => $this->input('type') !== WalletEntryType::Allocation->value),
                'integer',
                Rule::exists('investor_deals', 'id')->withoutTrashed(),
            ],

            // Money crossing the counter always says how. The one type that moves nothing
            // between the company and the investor — an allocation — has no method to name.
            'method' => [
                Rule::requiredIf(fn () => $this->input('type') !== WalletEntryType::Allocation->value),
                Rule::prohibitedIf(fn () => $this->input('type') === WalletEntryType::Allocation->value),
                'string',
                'max:20',
            ],

            'reference' => ['nullable', 'string', 'max:100'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'نوع الحركة مطلوب',
            'type.in' => 'هذه الحركة يكتبها النظام ولا تُسجَّل يدوياً',
            'amount.required' => 'المبلغ مطلوب',
            'amount.gt' => 'المبلغ يجب أن يكون أكبر من صفر',
            'investor_deal_id.required' => 'الصفقة مطلوبة عند تمويل صفقة',
            'investor_deal_id.prohibited' => 'هذه الحركة على المحفظة، لا على صفقة',
            'method.required' => 'طريقة الدفع مطلوبة',
            'method.prohibited' => 'تمويل الصفقة تحويل داخلي، بلا طريقة دفع',
            'occurred_at.before_or_equal' => 'لا يمكن تسجيل حركة بتاريخ مستقبلي',
        ];
    }
}
