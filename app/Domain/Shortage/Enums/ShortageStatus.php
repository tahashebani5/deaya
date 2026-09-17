<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Enums;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Shortage\Actions\RecalculateShortageTotals;

/**
 * Where a shortage is in its chase, and the only moves it may make from there.
 *
 * **This enum is the state machine**, the role {@see OrderStatus} plays for an order, sized down
 * to what chasing a missing sack actually needs. The map in {@see allowedNext()} is the single
 * definition of what is legal; the API refuses anything else and the app draws its buttons from
 * it, so no second copy of these rules exists to drift.
 *
 * **{@see Completed} is not in any map, and that is the feature.** It is written by
 * {@see RecalculateShortageTotals} and by nothing else, the moment what is left reaches zero.
 * «لا يتحوّل النقص إلى مكتمل إلا بعد توفير كامل الكمية» is a rule about arithmetic, not about
 * permission — a clerk who could pick it from a dropdown could close a shortage with twenty kilos
 * still missing, and the record would say the customer was served. Leaving it out of the map is
 * what makes that unrepresentable rather than merely guarded, and the app needs no rule of its
 * own: the status it must never offer is the one the list never contains.
 *
 * **{@see Unavailable} is not the end of the road**, which is the other thing this map says that
 * a reading of the four names would not. Stock turns up a month later, and a supply recorded
 * against an abandoned shortage reopens it — see {@see reopensTo()}. Refusing that would send the
 * employee to open a second shortage for the same sack, which is the shortest road to exactly the
 * duplication SHORTAGES-DESIGN §٣ exists to prevent.
 */
enum ShortageStatus: string
{
    /** Written down, nobody has started looking. Where every shortage begins, both sources. */
    case New = 'new';

    /** Somebody is on it. The status an assignment usually arrives with. */
    case Searching = 'searching';

    /** Looked for, not found — for now. Reopened by a supply, never by the calendar. */
    case Unavailable = 'unavailable';

    /** The whole quantity was supplied. Written by the arithmetic — see the class docblock. */
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'جديد',
            self::Searching => 'جاري البحث',
            self::Unavailable => 'غير متوفر',
            self::Completed => 'مكتمل',
        };
    }

    /**
     * Every move a person may make from here.
     *
     * {@see Completed} appears in none of them and leads nowhere: it is the arithmetic's to
     * write, and a shortage that is fully supplied has nothing left to chase. A correction that
     * needs to reopen one is a reversal of the supply that closed it, which runs the totals again
     * and lands the status wherever the remaining quantity says it belongs.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::New => [self::Searching, self::Unavailable],
            self::Searching => [self::Unavailable],
            self::Unavailable => [self::Searching],
            self::Completed => [],
        };
    }

    public function canMoveTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /**
     * Where a supply puts a shortage that was not being chased.
     *
     * Only {@see Unavailable} has anywhere to go: «جديد» and «جاري البحث» are already open, and
     * moving them would overwrite what the employee chose with something they did not. Null means
     * "leave the status alone", which is the answer for three of the four.
     *
     * {@see Completed} is not reopened here either — the totals decide that, and a supply
     * recorded against a closed shortage is refused before this is ever asked.
     */
    public function reopensTo(): ?self
    {
        return $this === self::Unavailable ? self::Searching : null;
    }

    /**
     * Finished — the record stands, and nothing more is chased.
     *
     * Only {@see Completed}. «غير متوفر» reads like an ending and is not one: the sack may still
     * turn up, and the shortage is still somebody's to close.
     */
    public function isFinal(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Whether a shortage in this status still counts as work outstanding.
     *
     * What "open" means on every screen and in every sweep that closes a cancelled order's
     * shortages: everything but {@see Completed}. Deliberately the inverse of {@see isFinal()}
     * rather than a second list — two lists of four cases are one list and one bug.
     */
    public function isOpen(): bool
    {
        return ! $this->isFinal();
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
