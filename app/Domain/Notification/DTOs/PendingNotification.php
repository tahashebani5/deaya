<?php

declare(strict_types=1);

namespace App\Domain\Notification\DTOs;

use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Notification\Actions\PublishNotification;
use App\Domain\Notification\Enums\NotificationType;
use Illuminate\Database\Eloquent\Model;

/**
 * A notification about to be published — what happened, before anyone has been worked out.
 *
 * The typed thing that crosses into {@see PublishNotification},
 * so a listener never hands the action a loose associative array (RULES.md §3).
 */
final readonly class PendingNotification
{
    /**
     * @param  array<string, mixed>  $payload  the facts, frozen — see NotificationDefinition::render()
     * @param  string|null  $subjectType  a morph **alias**, never a class name
     * @param  string|null  $dedupeKey  when set, an identical key published inside the storm
     *                                  window collapses into the existing row
     */
    public function __construct(
        public NotificationType $type,
        public array $payload,
        public ?string $subjectType = null,
        public ?int $subjectId = null,
        public ?int $causerId = null,
        public ?string $dedupeKey = null,
    ) {}

    /**
     * The usual way to build one: about a record, caused by somebody.
     *
     * Takes the alias from `getMorphClass()` rather than the class name, so `subject_type` holds
     * `order` and keeps resolving after the model moves between contexts — the whole reason
     * {@see AuditSubject} exists.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function about(
        NotificationType $type,
        Model $subject,
        array $payload,
        ?int $causerId = null,
        ?string $dedupeKey = null,
    ): self {
        return new self(
            type: $type,
            payload: $payload,
            subjectType: $subject->getMorphClass(),
            subjectId: (int) $subject->getKey(),
            causerId: $causerId,
            dedupeKey: $dedupeKey,
        );
    }
}
