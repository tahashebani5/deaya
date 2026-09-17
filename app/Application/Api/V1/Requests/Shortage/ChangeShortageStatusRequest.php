<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Shortage;

use App\Domain\Shortage\Actions\RecalculateShortageTotals;
use App\Domain\Shortage\Enums\ShortageStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving a shortage along the chase by hand.
 *
 * **«مكتمل» is not in the list, and «جديد» is not either.** The first is written by
 * {@see RecalculateShortageTotals} when the last of the quantity comes back and is never chosen —
 * a clerk who could pick it could close a shortage with twenty kilos still missing. The second is
 * where every shortage starts and nothing leads back to it: «لم تبدأ متابعته بعد» stops being true
 * the moment somebody starts.
 *
 * Whether the *move* is legal from where the shortage stands is a domain question, answered by
 * `ShortageStatus::canMoveTo()`. This only checks that the word means something a person may ask
 * for.
 */
class ChangeShortageStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in([
                ShortageStatus::Searching->value,
                ShortageStatus::Unavailable->value,
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'الحالة المطلوبة مطلوبة',
            'status.in' => 'لا يمكن تحويل النقص إلى هذه الحالة يدوياً',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['status' => 'الحالة'];
    }

    public function status(): ShortageStatus
    {
        return ShortageStatus::from((string) $this->validated('status'));
    }
}
