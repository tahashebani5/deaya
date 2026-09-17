<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\SupportTicket;

/**
 * Marks a conversation finished.
 *
 * Idempotent: closing a closed ticket writes nothing and is not an error. Two people clicking
 * the same button is not a failure, and the second one should not be told it is — nor should the
 * first person's name be overwritten by the second's.
 *
 * **Reopening is not here.** A customer reopens by replying — see {@see PostTicketMessage} —
 * which is the only reopen this system has, because it is the only one that comes with a reason
 * attached.
 */
final class CloseTicket
{
    public function __invoke(SupportTicket $ticket, ?User $staff = null): SupportTicket
    {
        if ($ticket->status === TicketStatus::Closed) {
            return $ticket;
        }

        $ticket->status = TicketStatus::Closed;
        $ticket->closed_at = now();
        $ticket->closed_by = $staff?->getKey();
        $ticket->save();

        return $ticket->refresh();
    }
}
