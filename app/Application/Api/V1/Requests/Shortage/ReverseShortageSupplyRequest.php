<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Shortage;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Undoing a supply entered in error.
 *
 * **The reason is required**, unlike the notes on the entry being undone. A correction to a money
 * ledger is read later by somebody trying to work out what happened, and «عُكست» with no sentence
 * beside it answers nothing — the same rule a cancellation's reason follows.
 */
class ReverseShortageSupplyRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.required' => 'سبب العكس مطلوب'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'سبب العكس'];
    }
}
