<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Order;

use App\Domain\Order\Enums\OrderDesignStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewOrderDesignRequest extends FormRequest
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
            // `proposed` is refused: a verdict is approve or reject, and "un-review" is not one
            // of the things a customer can say.
            'status' => ['required', Rule::enum(OrderDesignStatus::class)->except(OrderDesignStatus::Proposed)],

            'rejection_reason' => [
                'nullable', 'string', 'max:1000',
                'required_if:status,'.OrderDesignStatus::Rejected->value,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'نتيجة المراجعة مطلوبة',
            'rejection_reason.required_if' => 'رفض التصميم يتطلب ذكر السبب',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'status' => 'نتيجة المراجعة',
            'rejection_reason' => 'سبب الرفض',
        ];
    }
}
