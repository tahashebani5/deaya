<?php

declare(strict_types=1);

namespace App\Domain\Notification\Definitions;

use App\Domain\Notification\Audience\NotificationAudience;
use App\Domain\Notification\Contracts\NotificationDefinition;
use App\Domain\Notification\DTOs\RenderedNotification;

/**
 * «اجتماع الساعة ٤» — the one notification a person writes rather than the system deducing.
 *
 * ### The single exception to rendering from frozen facts
 *
 * Every other definition stores facts and builds the sentence in `render()`, so an Arabic typo
 * is one deploy rather than a permanently wrong row. Here the sentence **is** the fact: a human
 * already wrote it, there is nothing to derive it from, and echoing it back verbatim is the only
 * honest thing to do.
 *
 * **This must not become a precedent.** The rule it bends is what keeps every automatic
 * notification fixable after the fact; a definition that stores rendered text because it was
 * easier has given that up for no reason.
 *
 * ### And it has no route
 *
 * There is nothing to open. `route` is null, which is the case the app has to handle by drawing
 * a tile that simply does not navigate — the common case, not an edge one.
 */
final readonly class ManualAnnouncement implements NotificationDefinition
{
    /**
     * Whoever the sender chose: every employee, or one role.
     *
     * Rebuilt from the payload rather than stored as an object, because an audience is resolved
     * at publish time and only its result is kept. A role deleted afterwards changes nothing —
     * the recipient rows were written when it still existed.
     *
     * @param  array<string, mixed>  $payload
     */
    public function audience(array $payload): NotificationAudience
    {
        $roleId = $payload['role_id'] ?? null;

        return $roleId === null
            ? NotificationAudience::everyone()
            : NotificationAudience::role((int) $roleId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function render(array $payload): RenderedNotification
    {
        return new RenderedNotification(
            title: (string) ($payload['title'] ?? ''),
            body: (string) ($payload['body'] ?? ''),
            route: null,
        );
    }

    /**
     * The sender knows what they just sent. Their own bell staying quiet is correct behaviour,
     * not a delivery that went missing.
     */
    public function notifiesCauser(): bool
    {
        return false;
    }
}
