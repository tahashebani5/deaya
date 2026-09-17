<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Investor;

use App\Domain\Investor\Enums\DealExpenseKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A cost booked against a deal.
 *
 * **`is_landed` is absent and always will be.** Whether a cost is already inside the goods is
 * something only the server can know — it is true exactly for a row mirrored from a purchase
 * order's own additional costs — and a client that could send it could have an investor charged
 * for one shipping invoice twice.
 */
class StoreDealExpenseRequest extends FormRequest
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
            'kind' => ['required', Rule::enum(DealExpenseKind::class)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'incurred_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kind.required' => 'نوع المصروف مطلوب',
            'name.required' => 'بيان المصروف مطلوب',
            'amount.gt' => 'المبلغ يجب أن يكون أكبر من صفر',
            'incurred_on.required' => 'تاريخ المصروف مطلوب',
        ];
    }
}
