<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Exceptions;

use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Support\Exceptions\DomainException;

/**
 * Posting a shipment against a purchase order that is already `completed` or `cancelled`.
 * A finished order has nothing left to receive, and a cancelled one was never going to be
 * fulfilled in the first place.
 */
final class PurchaseOrderNotReceivable extends DomainException
{
    public static function make(PurchaseOrderStatus $status): self
    {
        return new self("لا يمكن استلام شحنة على أمر شراء في حالة «{$status->label()}»");
    }
}
