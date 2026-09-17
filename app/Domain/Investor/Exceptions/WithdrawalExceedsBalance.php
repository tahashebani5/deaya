<?php

declare(strict_types=1);

namespace App\Domain\Investor\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * You cannot take out more than is there.
 *
 * Read from the row held under the lock, never from a figure fetched before it — the
 * `RecordOrderPayment` discipline, and the only thing that makes two simultaneous withdrawals
 * safe.
 */
final class WithdrawalExceedsBalance extends DomainException
{
    public static function make(string $requested, string $available): self
    {
        return new self("المبلغ المطلوب {$requested} أكبر من الرصيد المتاح {$available}");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['amount' => [$this->getMessage()]];
    }
}
