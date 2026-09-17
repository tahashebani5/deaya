<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * An entry of nothing is not an event.
 *
 * The FormRequest already refuses it with a friendlier message, and the table holds a CHECK that
 * refuses it absolutely. This exists so the rule also holds for a console command or an importer
 * — a validation rule guards one door, and this ledger is meant to have no unguarded ones.
 */
final class PaymentAmountMustBePositive extends DomainException
{
    public static function make(string $amount): self
    {
        return new self("المبلغ ({$amount}) يجب أن يكون أكبر من صفر");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['amount' => [$this->getMessage()]];
    }
}
