<?php

declare(strict_types=1);

namespace App\Domain\Support\Enums;

use App\Domain\Order\Enums\OrderStatus;

/**
 * Where a support ticket has got to.
 *
 * **Three, and the middle one earns its place.** «مفتوحة» and «مغلقة» alone would make an
 * unanswered question and one somebody is actively working on look identical in the list, which
 * is the difference the person triaging most needs to see — and the difference the customer most
 * wants: «قيد المعالجة» means a human has it.
 *
 * Unlike {@see OrderStatus} there is no transition map here, and that is
 * deliberate. An order's statuses describe physical work that happens in one order; a ticket
 * moves back and forth as a conversation does — answered, reopened by a reply, answered again —
 * and a machine enforcing an order on that would be a machine to fight.
 */
enum TicketStatus: string
{
    /** Nobody has answered yet. Every ticket starts here. */
    case Open = 'open';

    /** A member of staff has replied, and it is on somebody's desk. */
    case InProgress = 'in_progress';

    /** Done. A customer reply reopens it — see `PostTicketMessage`. */
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'مفتوحة',
            self::InProgress => 'قيد المعالجة',
            self::Closed => 'مغلقة',
        };
    }

    /** Whether the conversation is still live — what the app's «مفتوحة» filter means. */
    public function isOpen(): bool
    {
        return $this !== self::Closed;
    }
}
