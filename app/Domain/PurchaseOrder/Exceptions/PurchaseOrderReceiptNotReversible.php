<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Exceptions;

use App\Domain\PurchaseOrder\Actions\ReversePurchaseOrderReceipt;
use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Support\Exceptions\DomainException;

/**
 * There is no receipt on this order to undo.
 *
 * Either nothing has been received against it yet — an order still `new`, `arrived` or
 * `cancelled` has no stock on a shelf and nothing to restore, and cancelling it is the act it is
 * actually asking for — or the one receipt it carried has already been taken back.
 *
 * @see ReversePurchaseOrderReceipt
 */
final class PurchaseOrderReceiptNotReversible extends DomainException
{
    public static function make(PurchaseOrderStatus $status): self
    {
        return new self(
            "لا يوجد استلام يمكن التراجع عنه على أمر شراء في حالة «{$status->label()}»"
        );
    }
}
