<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Support\Models\SupportTicket;

/**
 * Moves one side's read cursor to now.
 *
 * Its own action although it is a single assignment, because *which* cursor is the whole of the
 * decision and it must not be made twice in two controllers. Called whenever a thread is opened
 * — reading a conversation is what marks it read, and a separate «mark as read» button would be
 * a thing to forget to press.
 */
final class MarkTicketRead
{
    public function __invoke(SupportTicket $ticket, bool $staff): void
    {
        $column = $staff ? 'staff_read_at' : 'customer_read_at';

        $ticket->forceFill([$column => now()])->save();
    }
}
