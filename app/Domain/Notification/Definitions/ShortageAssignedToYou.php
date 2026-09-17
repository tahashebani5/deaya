<?php

declare(strict_types=1);

namespace App\Domain\Notification\Definitions;

use App\Domain\Notification\Audience\NotificationAudience;
use App\Domain\Notification\Contracts\NotificationDefinition;
use App\Domain\Notification\DTOs\RenderedNotification;

/**
 * Somebody has been made responsible for chasing a shortage.
 *
 * **The first notification in the system with an audience of one**, and the reason the audience
 * vocabulary already had `user()` waiting for it. Every other type answers «من يهمّه هذا؟» with a
 * permission, because the business settled that question once on the roles screen. This one has a
 * different shape of answer: the work now belongs to a named person, and sending it to everybody
 * who can read shortages would teach the whole shop to scroll past a number — the same argument
 * `orders.ready_message` makes for having its own grant.
 */
final readonly class ShortageAssignedToYou implements NotificationDefinition
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function audience(array $payload): NotificationAudience
    {
        return NotificationAudience::user((int) ($payload['assignee_id'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function render(array $payload): RenderedNotification
    {
        $code = (string) ($payload['shortage_code'] ?? '');
        $name = (string) ($payload['name'] ?? '');
        $quantity = (string) ($payload['remaining'] ?? '');
        $unit = (string) ($payload['unit_label'] ?? '');

        return new RenderedNotification(
            title: 'نقص مُسنَد إليك',
            // What is short and how much of it — the two things that decide whether this is a
            // phone call now or a job for the morning. The order it came off is on the screen the
            // route opens, and naming it here would push the quantity off a lock screen.
            body: trim("{$name} — {$quantity} {$unit}") !== '—'
                ? trim("{$name} — {$quantity} {$unit}")
                : "نقص {$code}",
            route: '/shortages/'.($payload['shortage_id'] ?? ''),
        );
    }

    /**
     * Somebody who assigned a shortage to themselves watched it happen on their own screen.
     */
    public function notifiesCauser(): bool
    {
        return false;
    }
}
