<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Forgiving more than is owed.
 *
 * **A write-off is bounded by the debt it closes, and nothing else.** Anything above that is
 * either a typo — 50 where 5 was meant, on an order with 5 outstanding — or an attempt to
 * express something a write-off cannot say. Money already collected does not come back through
 * this door: that is a refund, which moves cash and is guarded by `RefundExceedsPaid`.
 *
 * Refused rather than trimmed to fit. Silently writing off 5 when somebody typed 50 would be the
 * system deciding what they meant, and the number it landed on would be the one nobody checked.
 */
final class WriteOffExceedsRemaining extends DomainException
{
    public static function make(string $amount, string $remaining): self
    {
        return new self("المبلغ ({$amount}) أكبر من المتبقي على الطلبية ({$remaining}) — لا يُشطب إلا ما هو مستحق");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['amount' => [$this->getMessage()]];
    }
}
