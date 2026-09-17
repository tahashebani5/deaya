<?php

declare(strict_types=1);

namespace App\Domain\Audit\Queries;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditSubject;
use Carbon\CarbonImmutable;

/**
 * What a history screen is asking for.
 *
 * Readonly and typed, so nothing past this point deals in `$request->get('event')`. Every field
 * is optional: no filter at all is the common case, and means "the whole trail".
 */
final readonly class ActivityFilters
{
    public function __construct(
        /** created · updated · deleted · restored */
        public ?AuditEvent $event = null,

        /** Only what this user did. */
        public ?int $causerId = null,

        /** Only this kind of record — used by the global feed, ignored by per-record ones. */
        public ?AuditSubject $subjectType = null,

        /** Inclusive: the whole of this day counts. */
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
    ) {}

    /**
     * Built from already-validated request data — the one place an array is allowed to cross
     * into the domain.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            event: self::enum(AuditEvent::class, $data['event'] ?? null),
            causerId: isset($data['causer_id']) && $data['causer_id'] !== '' ? (int) $data['causer_id'] : null,
            subjectType: self::enum(AuditSubject::class, $data['subject_type'] ?? null),
            from: self::date($data['from'] ?? null)?->startOfDay(),
            // End of day, not midnight: `to=2026-07-31` plainly means "including today", and a
            // bare date parsed as 00:00 would exclude every entry the day actually contains.
            to: self::date($data['to'] ?? null)?->endOfDay(),
        );
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private static function enum(string $enum, mixed $value): ?object
    {
        return is_string($value) && $value !== '' ? $enum::tryFrom($value) : null;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
