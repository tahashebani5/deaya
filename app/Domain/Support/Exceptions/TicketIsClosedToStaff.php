<?php

declare(strict_types=1);

namespace App\Domain\Support\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Staff tried to write into a ticket the shop had closed.
 *
 * **Refused for staff and allowed for the customer**, which is the asymmetry worth explaining. A
 * customer replying to a thread they can still see means «this is not finished», and reopening
 * is exactly what they intended. Staff adding to a closed ticket would be reopening a
 * conversation the shop decided was over without saying so — so the ticket is reopened
 * deliberately first, and the reply follows.
 */
final class TicketIsClosedToStaff extends DomainException
{
    public static function make(): self
    {
        return new self('التذكرة مغلقة. أعد فتحها قبل الرد');
    }
}
