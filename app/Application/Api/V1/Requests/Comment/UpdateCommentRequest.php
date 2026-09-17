<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Comment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rewriting a note. The text is the only thing an edit can touch.
 *
 * Not the author and not the record it is about: a note is a sentence somebody said about somebody, and
 * an edit that could move either of those would turn a record into a forgery.
 */
class UpdateCommentRequest extends FormRequest
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
        // Written out rather than borrowed from the store request: Scramble analyses this method
        // statically, and anything it cannot follow publishes an endpoint with no request body.
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
