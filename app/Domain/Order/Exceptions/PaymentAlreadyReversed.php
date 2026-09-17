<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * An entry is undone once.
 *
 * A second reversal would subtract the amount twice and leave the order reading as though money
 * had been taken back that was never taken. A partial unique index on `reverses_payment_id` is
 * the actual guarantee — two concurrent requests both pass this check before either commits —
 * and this exception is what turns that race into a readable 422 instead of a 500.
 */
final class PaymentAlreadyReversed extends DomainException
{
    public static function make(int $paymentId): self
    {
        return new self("الدفعة رقم {$paymentId} ملغاة بالفعل");
    }
}
