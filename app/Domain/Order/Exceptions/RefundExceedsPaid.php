<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * We cannot hand back money we were never given.
 *
 * A refund larger than what the order has been paid would drive `paid_amount` negative, and a
 * negative paid total is not a state this business has — it is a debt to the customer, which is
 * a customer account, which does not exist here yet.
 */
final class RefundExceedsPaid extends DomainException
{
    public static function make(string $amount, string $paid): self
    {
        return new self("المبلغ المردود ({$amount}) أكبر من المدفوع على الطلبية ({$paid})");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['amount' => [$this->getMessage()]];
    }
}
