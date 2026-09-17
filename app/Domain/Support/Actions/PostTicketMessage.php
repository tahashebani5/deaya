<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Exceptions\TicketIsClosedToStaff;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Models\TicketMessage;
use Illuminate\Support\Facades\DB;

/**
 * Adds one message to a thread, and moves the ticket the way a reply moves it.
 *
 * **The status is a consequence of who spoke, not a field anybody sets.** Staff replying puts a
 * ticket on somebody's desk; a customer replying to something we had closed reopens it, because
 * a closed ticket that a person is still writing into is not closed — it is closed on paper and
 * open in fact, and that gap is where a customer gets ignored.
 *
 * **A customer may write into a closed ticket; staff may not.** The asymmetry is deliberate. For
 * the customer it is the only reasonable reading of «رد» on a thread they can still see, and the
 * reopen is exactly what they meant. For staff it would be re-opening a conversation the shop
 * had decided was over without saying so: if they have something to add, the ticket gets
 * reopened on purpose first.
 *
 * The read cursor moves for the writer in the same transaction — you have read what you just
 * wrote — so a reply never leaves its own author with an unread badge.
 */
final class PostTicketMessage
{
    public function __invoke(
        SupportTicket $ticket,
        string $body,
        ?User $staff = null,
        ?Customer $customer = null,
    ): TicketMessage {
        if ($staff !== null && $ticket->status === TicketStatus::Closed) {
            throw TicketIsClosedToStaff::make();
        }

        return DB::transaction(function () use ($ticket, $body, $staff, $customer): TicketMessage {
            $message = new TicketMessage(['body' => $body]);

            $message->support_ticket_id = $ticket->getKey();
            // Stamped here and nowhere else. Exactly one, which the table's CHECK also demands.
            $message->user_id = $staff?->getKey();
            $message->customer_id = $customer?->getKey();
            $message->save();

            $ticket->last_message_at = $message->created_at;

            if ($staff !== null) {
                // The shop has answered, so it is on a desk now.
                $ticket->status = TicketStatus::InProgress;
                $ticket->staff_read_at = $message->created_at;
            } else {
                // A customer writing into a closed thread reopens it — see the class comment.
                if ($ticket->status === TicketStatus::Closed) {
                    $ticket->status = TicketStatus::Open;
                    $ticket->closed_at = null;
                    $ticket->closed_by = null;
                }

                $ticket->customer_read_at = $message->created_at;
            }

            $ticket->save();

            return $message;
        });
    }
}
