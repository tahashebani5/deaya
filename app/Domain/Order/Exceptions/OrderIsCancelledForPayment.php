<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Nobody pays for an order that was written off.
 *
 * **The one closed door, and only in one direction.** Refunds and reversals stay open on a
 * cancelled order — in fact that is the status where a deposit most often *has* to go back, and
 * a lock that stopped it would be a bug wearing a safety jacket. Only taking *new* money is
 * refused, because there is nothing left to take it for.
 *
 * A cancellation is deliberately the only status that closes this. An order can be paid before
 * it is designed, after it is delivered, or on the day it is settled — the payment axis and the
 * workflow axis are independent, which is why they were never merged into one enum.
 */
final class OrderIsCancelledForPayment extends DomainException
{
    public static function make(string $code): self
    {
        return new self("الطلبية رقم {$code} ملغاة — لا يمكن تسجيل دفعة عليها. الردّ والإلغاء ما زالا متاحين");
    }
}
