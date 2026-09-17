<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * More was recorded as settled at the carrier than the order still owes.
 *
 * The same ceiling {@see PaymentExceedsRemaining} and {@see WriteOffExceedsRemaining} impose, and
 * for the same reason: an entry that closes more than the debt leaves the order overpaid on paper
 * with nobody having overpaid anything.
 *
 * **It matters more here than on either of those**, because nothing human is holding the pen. A
 * repeated webhook is routine, and without this ceiling a second delivery notice for the same
 * parcel would close the order a second time.
 */
final class CarrierSettlementExceedsRemaining extends DomainException
{
    public static function make(string $amount, string $remaining): self
    {
        return new self(
            "المبلغ المسدَّد لدى الناقل ({$amount}) أكبر من المتبقي على الطلبية ({$remaining})",
        );
    }
}
