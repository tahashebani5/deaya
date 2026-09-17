<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Order;

use App\Domain\Order\Actions\ReinstateCancelledOrder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Undoing a cancellation.
 *
 * **One optional field, and no destination among them.** Where the order goes back to is read
 * from its own timeline — see
 * {@see ReinstateCancelledOrder} — so there is nothing here for a
 * caller to name, and a payload that tried would be describing a decision this endpoint does
 * not offer.
 *
 * The permission is declared on the route with `can:` like every other endpoint's, because
 * unlike a status change this one does not vary with the body: it always costs
 * `orders.status.cancelled`. Whoever the business trusts to write an order off is who it trusts
 * to say the write-off was a mistake.
 */
class ReinstateOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A note on the row, never a condition of the move. The cancellation being undone
            // already carries the sentence that matters, and it stays in the timeline.
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'السبب'];
    }
}
