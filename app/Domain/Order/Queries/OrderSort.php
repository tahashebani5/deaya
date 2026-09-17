<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries;

/**
 * Which end of the queue the list starts at.
 *
 * **Two cases and no more.** Sorting by total, by customer or by status was considered and left
 * out: a work queue is read in one dimension — time — and the other three are questions the
 * filters already answer better. A sort menu of six entries is six taps to find the two anybody
 * uses.
 *
 * The wire values are words rather than `asc`/`desc`, because what the user picks is «الأقدم
 * أولاً», not a direction on a column they have never heard of — and the column is this enum's
 * business, not the client's.
 */
enum OrderSort: string
{
    /** What the list has always done: the order somebody rang about five minutes ago, first. */
    case Newest = 'newest';

    /** What has been waiting longest — the queue read from its far end. */
    case Oldest = 'oldest';

    /**
     * A value nobody named falls back rather than being refused.
     *
     * A 422 for a typo in a query string would turn a mistyped link into a screen with no orders
     * on it, which reads as «لا توجد طلبيات» — the same trap {@see OrderFilters::statuses()}
     * avoids by dropping a status that names nothing.
     */
    public static function fromRequest(mixed $value): self
    {
        return self::tryFrom((string) ($value ?? '')) ?? self::Newest;
    }

    /** The direction both of the list's sort keys take. */
    public function direction(): string
    {
        return $this === self::Oldest ? 'asc' : 'desc';
    }

    public function label(): string
    {
        return match ($this) {
            self::Newest => 'الأحدث أولاً',
            self::Oldest => 'الأقدم أولاً',
        };
    }
}
