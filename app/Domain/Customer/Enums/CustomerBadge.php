<?php

declare(strict_types=1);

namespace App\Domain\Customer\Enums;

use App\Domain\Customer\Actions\CountCustomerBadges;

/**
 * Something waiting for the customer's attention, said as a number on a tile.
 *
 * **One enum so that adding the next badge is a case and an arm, not a new endpoint.** The app
 * asks once and is handed every badge at its current count; a second thing worth a number later —
 * a design awaiting «موافق», an order that needs an answer, a notification — becomes a case here
 * and an arm in {@see CountCustomerBadges}, which is a `match` with no `default` and therefore
 * fails the build until somebody says how to count it.
 *
 * **Zeros travel too.** A badge the app is currently drawing has to be told it is over, and an
 * endpoint that omitted the empty ones would leave the last number on screen forever.
 *
 * **What belongs here:** something the customer can act on, that they would want to know about
 * before opening the screen it lives on. Not a status, not a total, and never a count of what
 * the *shop* still owes itself.
 */
enum CustomerBadge: string
{
    /**
     * Replies from the shop the customer has not read.
     *
     * Counted from `support_tickets.customer_read_at` rather than a stored counter, for the same
     * reason the per-ticket number is: a column somebody has to remember to decrement drifts the
     * first time a code path forgets, and a badge that lies is worse than no badge.
     */
    case Support = 'support';

    /**
     * What the tile it sits on is called — for the API's own documentation and for anybody
     * reading a payload, never drawn by the app. The app knows its own screens' names.
     */
    public function label(): string
    {
        return match ($this) {
            self::Support => 'الدعم',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $badge) => $badge->value, self::cases());
    }
}
