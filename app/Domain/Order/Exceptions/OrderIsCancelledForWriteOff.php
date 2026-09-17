<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * There is no debt on an order that was called off.
 *
 * A write-off says «هذا المستحق لن يُحصَّل، وأغلقناه» — and a cancelled order has no such
 * amount: it never reached the customer, so nothing was ever owed for it. Writing one off would
 * put a loss in the books against bags that were never handed over, and leave the order reading
 * «مشطوب فرقها» when the truth is that it was called off.
 *
 * **The other money paths stay open on a cancelled order**, and deliberately — see
 * {@see OrderIsCancelledForPayment}. A deposit taken before the cancellation still has to be
 * refundable, and an entry typed by mistake still has to be reversible.
 */
final class OrderIsCancelledForWriteOff extends DomainException
{
    public static function make(string $code): self
    {
        return new self("الطلبية رقم {$code} ملغاة — لا مستحق عليها ليُشطب");
    }
}
