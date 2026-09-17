<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Enums\OrderPaymentType;
use App\Support\Exceptions\DomainException;

/**
 * A reversal says "this never happened". Only an entry that *claimed* something can be wrong in
 * that way.
 *
 * **Two kinds can: a payment and a write-off.** Both are assertions somebody typed — money was
 * taken, a debt was forgiven — and both can simply be untrue: the wrong figure, the wrong order.
 * Neither moved cash, so undoing one asks nobody to go back to the drawer.
 *
 * **Reversing a reversal is a maze with no floor.** The second one would have to mean "the
 * correction itself was a mistake", which is the same thing as the money having been paid — and
 * a clerk reading three rows to work that out is worse off than one reading a fresh payment.
 * Somebody who cancelled an entry in error records the payment again.
 *
 * **A refund is not reversed either**, and for a different reason: cash genuinely left the
 * drawer. If the customer hands it back, that is a payment — an event — not a claim that the
 * refund never occurred.
 */
final class EntryCannotBeReversed extends DomainException
{
    public static function make(OrderPaymentType $type): self
    {
        return new self("لا يمكن إلغاء قيد من نوع «{$type->label()}» — الإلغاء للدفعات وقيود الشطب وحدها");
    }
}
