<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Exceptions;

use App\Domain\Order\Exceptions\DesignDoesNotBelongToCustomer;
use App\Support\Exceptions\DomainException;

/**
 * A received line names a stock item that was never ordered on this purchase order. A 422 rather
 * than a 404 on purpose: the item exists, it simply is not one of this order's lines — the same
 * reasoning {@see DesignDoesNotBelongToCustomer} carries.
 */
final class StockItemNotOnPurchaseOrder extends DomainException
{
    public static function make(int $stockItemId, int $purchaseOrderId): self
    {
        return new self("المادة رقم {$stockItemId} ليست ضمن بنود أمر الشراء رقم {$purchaseOrderId}");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['items' => [$this->getMessage()]];
    }
}
