<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Customer\Models\Customer;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\SupportTicket;
use Illuminate\Support\Facades\DB;

/**
 * A customer starts a conversation.
 *
 * **The ticket and its first message are one transaction**, because a ticket with no message is
 * a subject line nobody can answer — it would sit in the queue looking like work and carrying
 * no question.
 *
 * `order_id` is checked by the caller, not here: «this order is not yours» is a question about
 * the Order context, and this action takes an id it has been told is good. The client request
 * scopes it to the signed-in customer's own orders.
 */
final class OpenTicket
{
    public function __construct(private readonly PostTicketMessage $postMessage) {}

    public function __invoke(
        Customer $customer,
        string $subject,
        string $body,
        ?int $orderId = null,
    ): SupportTicket {
        return DB::transaction(function () use ($customer, $subject, $body, $orderId): SupportTicket {
            $ticket = new SupportTicket(['subject' => $subject, 'order_id' => $orderId]);

            // Neither is fillable: a ticket never changes hands, and the status is moved by
            // actions that know what a move means rather than by a request body.
            $ticket->customer_id = $customer->getKey();
            $ticket->status = TicketStatus::Open;
            $ticket->save();

            ($this->postMessage)($ticket, $body, customer: $customer);

            return $ticket->refresh();
        });
    }
}
