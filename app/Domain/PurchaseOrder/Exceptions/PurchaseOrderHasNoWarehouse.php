<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Receiving against a purchase order whose destination warehouse is gone.
 *
 * `warehouse_id` is required when a purchase order is created — the same rule
 * `StoreStockArrivalRequest` holds for a plain arrival — but the column itself is nullable and
 * `nullOnDelete`, so a warehouse deleted after the fact (once it held no stock) leaves the order
 * pointing at nothing. There is no destination left to receive into until someone edits the
 * order, which `PurchaseOrderNotEditable` already refuses once it has left `new`.
 */
final class PurchaseOrderHasNoWarehouse extends DomainException
{
    public static function make(int $purchaseOrderId): self
    {
        return new self("أمر الشراء رقم {$purchaseOrderId} ليس له مخزن وجهة، تعذّر استلام الشحنة عليه");
    }
}
