<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources\Client;

use App\Domain\Support\Models\TicketMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One message in a thread, as the customer reads it.
 *
 * **`from` is `me` or `support`, and the staff member's name is not sent.** Which colleague
 * happened to answer is the shop's internal arrangement; to the customer the answer came from
 * the shop. Sending the name would also make an individual the target of a complaint about a
 * decision the business made.
 *
 * @mixin TicketMessage
 */
class ClientTicketMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Decided here rather than left to the app to infer from two nullable ids.
            'from' => $this->isFromCustomer() ? 'me' : 'support',
            'body' => $this->body,
            'sent_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
