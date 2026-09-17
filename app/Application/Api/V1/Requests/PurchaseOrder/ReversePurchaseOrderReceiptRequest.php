<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Undoing a receipt posted against a purchase order.
 *
 * **One required field, and it is the reason.** Everything else this act needs is already
 * known — which shipment, which lines, which warehouse — so the only thing left to ask is what
 * went wrong. Required rather than optional on purpose: a stock correction nobody has to account
 * for is precisely what the window and the audit trail exist to prevent, and a blank reason
 * would make both decorative.
 *
 * Who may do it at all is the route's business (`inventory.manage`), and whether they may do it
 * *now* is the domain's — see `ReverseStockArrival`, which owns the 24-hour window, and
 * `PurchaseOrderReceiptNotReversible`, which owns whether there is a receipt to undo. Neither
 * can be answered here without reading the database twice.
 */
class ReversePurchaseOrderReceiptRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'سبب التراجع مطلوب',
            'reason.min' => 'سبب التراجع قصير جداً',
            'reason.max' => 'سبب التراجع طويل جداً',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'سبب التراجع',
        ];
    }
}
