<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Attaching another customer's artwork to this order. A 422 rather than a 404 on purpose: the
 * design exists and the caller may well be allowed to see it — it simply is not this customer's.
 */
final class DesignDoesNotBelongToCustomer extends DomainException
{
    public static function make(int $designId, int $customerId): self
    {
        return new self("التصميم رقم {$designId} لا ينتمي للعميل رقم {$customerId}");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['customer_design_id' => [$this->getMessage()]];
    }
}
