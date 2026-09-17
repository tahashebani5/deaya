<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Exceptions;

use App\Domain\Customer\Exceptions\ShopDoesNotBelongToCustomer;
use App\Support\Exceptions\DomainException;

/**
 * An update names an additional-cost id that is not one of this purchase order's own — the same
 * shape {@see PurchaseOrderItemDoesNotBelongToOrder} refuses for a line, and
 * {@see ShopDoesNotBelongToCustomer} refuses for a customer's shops, and for the same reason: a
 * caller naming a specific cost and getting a different one updated is a bug worth surfacing.
 */
final class PurchaseOrderAdditionalCostDoesNotBelongToOrder extends DomainException
{
    public static function make(int $additionalCostId, int $purchaseOrderId): self
    {
        return new self("التكلفة الإضافية رقم {$additionalCostId} لا تنتمي لأمر الشراء رقم {$purchaseOrderId}");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['additional_costs' => [$this->getMessage()]];
    }
}
