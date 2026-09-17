<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Exceptions;

use App\Domain\Customer\Exceptions\ShopDoesNotBelongToCustomer;
use App\Support\Exceptions\DomainException;

/**
 * An update names a line id that is not one of this purchase order's own — the same shape
 * {@see ShopDoesNotBelongToCustomer} refuses for a customer's
 * shops, and for the same reason: a caller naming a specific line and getting a different one
 * updated is a bug worth surfacing, not something to silently work around.
 */
final class PurchaseOrderItemDoesNotBelongToOrder extends DomainException
{
    public static function make(int $itemId, int $purchaseOrderId): self
    {
        return new self("البند رقم {$itemId} لا ينتمي لأمر الشراء رقم {$purchaseOrderId}");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['items' => [$this->getMessage()]];
    }
}
