<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Client\Support;

use Illuminate\Foundation\Http\FormRequest;

/** A reply, from either side. One field. */
class PostTicketMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:2000']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['body.required' => 'اكتب رسالتك'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['body' => 'الرسالة'];
    }
}
