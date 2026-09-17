<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Investor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The person, and nothing about his money.
 *
 * No `code` — it is allocated from the id, the way an order's number is. No `user_id` — a login
 * is created through its own endpoint, because it writes a `users` row and that is a different
 * grant. And no unique rule on the name: this is a register of people, and two men may share one.
 */
class StoreInvestorRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم المستثمر مطلوب',
            'name.min' => 'اسم المستثمر قصير جداً',
            'name.max' => 'اسم المستثمر طويل جداً',
        ];
    }
}
