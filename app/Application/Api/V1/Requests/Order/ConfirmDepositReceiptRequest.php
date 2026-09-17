<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirming — or unconfirming — that the عربون actually reached the account.
 *
 * The permission is on the route (`orders.deposit.confirm`): this endpoint costs the same grant
 * whatever the body says. **The rule that the person who claimed the deposit may not confirm it
 * is not here**, because it is a fact about the order rather than about the request — it lives in
 * `ConfirmDepositReceipt`, where a console command meets it too, and is published to the app as
 * `can_confirm_deposit` so the box is greyed before anybody taps it.
 *
 * **`received` is required and not defaulted**, exactly as `sent` is on the ready-message
 * endpoint: a missing key would silently mean «نعم» on the one request where guessing is worst —
 * its other direction is the correction of a stray tap.
 */
class ConfirmDepositReceiptRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Whether the عربون has been seen in the account. `false` takes an earlier confirmation back. */
            'received' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'received.required' => 'حدّد ما إذا كان العربون قد وصل',
            'received.boolean' => 'قيمة الاستلام يجب أن تكون نعم أو لا',
        ];
    }
}
