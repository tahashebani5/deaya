<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Client\Support;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A customer starting a conversation.
 *
 * **`order_id` is validated for existence only; whose order it is belongs to the controller**,
 * which scopes the lookup to the signed-in customer's own orders. An `exists` rule here would
 * answer «this order exists» for somebody else's id, and the refusal a customer should get is
 * the same one they get for an id that was never real.
 */
class OpenTicketRequest extends FormRequest
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
        return [
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:2000'],
            'order_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject.required' => 'اكتب موضوع التذكرة',
            'body.required' => 'اكتب رسالتك',
            'body.max' => 'الرسالة طويلة. اختصرها أو أرسلها على دفعات',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['subject' => 'الموضوع', 'body' => 'الرسالة', 'order_id' => 'الطلبية'];
    }
}
