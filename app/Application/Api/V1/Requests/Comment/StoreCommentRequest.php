<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Comment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A note about a record — a customer, a supplier — one field, and one rule worth stating.
 *
 * The body is trimmed *before* validation, so a line of spaces fails `required` instead of
 * passing it. A blank note satisfies every check and tells the next reader nothing.
 */
class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('body')) {
            $this->merge(['body' => trim((string) $this->input('body'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // 2000 characters: long enough for the story behind a difficult delivery, short enough
        // that nobody pastes a contract in here.
        return ['body' => ['required', 'string', 'max:2000']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['body' => 'الملاحظة'];
    }
}
