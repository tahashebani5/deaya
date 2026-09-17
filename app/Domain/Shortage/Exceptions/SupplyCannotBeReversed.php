<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Exceptions;

use App\Domain\Shortage\Enums\SupplyKind;
use App\Support\Exceptions\DomainException;

/**
 * Only a purchase may be reversed.
 *
 * A `resolved_externally` row is not an entry somebody made — it is the sync's record that the
 * quantity came back through the order screen instead. Reversing it here would leave this table
 * disagreeing with the line it was derived from, and the next sync would simply write it again.
 * The road back is the order, which re-runs the reconciliation. See {@see SupplyKind}.
 *
 * A reversal is not itself reversible, for the reason ORDER-DELETE-AND-ARCHIVE §٢٫١ gives about
 * payments: undoing an undo is a third financial event nobody asked for. The entry is recorded
 * again by hand if the purchase really was made.
 */
final class SupplyCannotBeReversed extends DomainException
{
    public static function externalArrival(): self
    {
        return new self('لا تُعكس كمية وصلت من الطلبية — تُصحَّح من شاشة الطلبية');
    }

    public static function itIsAReversal(): self
    {
        return new self('لا يُعكس قيدٌ عكسي — تُسجَّل العملية من جديد إن كان الشراء قد تمّ فعلاً');
    }
}
