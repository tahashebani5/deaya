<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A cost layer somebody else financed is not repriced by us.
 *
 * Raising the cost of a funded layer lowers the investor's share of every sale still to come out
 * of it; lowering it inflates his share at the company's expense. Either way it is one party to
 * an arrangement moving the number the other party is paid on — and doing it behind a grant
 * («تصحيح التكلفة») granted for something else entirely.
 *
 * A cost agreed at the start stays agreed. A late shipping or customs invoice is recorded as a
 * deal expense instead, which lands on the investor's own statement where he can see it.
 */
final class BatchIsFundedByADeal extends DomainException
{
    public static function make(int $batchId): self
    {
        return new self("الدفعة رقم {$batchId} تموّلها صفقة مستثمر، ولا تُعدَّل تكلفتها");
    }
}
