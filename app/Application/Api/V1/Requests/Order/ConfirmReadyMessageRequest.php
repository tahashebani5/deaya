<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirming — or unconfirming — that the customer was told their order is ready.
 *
 * The permission is on the route: unlike a status change this endpoint costs the same grant
 * whatever the body says, and unlike an order edit it has exactly one field.
 *
 * **`sent` is required and not defaulted.** A missing key would silently mean «نعم» on an
 * endpoint whose other direction is a correction of a stray tap — the one request where guessing
 * is worst.
 */
class ConfirmReadyMessageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Whether the message has been sent. `false` takes an earlier confirmation back. */
            'sent' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sent.required' => 'حدّد ما إذا كانت الرسالة قد أُرسلت',
            'sent.boolean' => 'قيمة الإرسال يجب أن تكون نعم أو لا',
        ];
    }
}
