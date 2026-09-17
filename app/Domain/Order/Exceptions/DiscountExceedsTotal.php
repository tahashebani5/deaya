<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A negative grand total is not a discount, it is a refund — and this system has no concept of
 * one. Caught here rather than clamped, because silently reducing the discount to fit would
 * charge a customer more than the clerk told them.
 */
final class DiscountExceedsTotal extends DomainException
{
    public static function make(string $discount, string $total): self
    {
        return new self("الخصم ({$discount}) أكبر من إجمالي الطلبية ({$total})");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['discount' => [$this->getMessage()]];
    }
}
