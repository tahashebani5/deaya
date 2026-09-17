<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Actions;

use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrder\Exceptions\PurchaseOrderTransitionNotAllowed;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;

/**
 * Writes a purchase order off. Allowed from {@see PurchaseOrderStatus::New} or
 * {@see PurchaseOrderStatus::Arrived} only — a completed order has nothing left to cancel, and
 * a cancelled one already is one.
 *
 * @throws PurchaseOrderTransitionNotAllowed
 */
final class CancelPurchaseOrder
{
    public function __invoke(PurchaseOrder $order): PurchaseOrder
    {
        $from = $order->status;

        if (! $from->canMoveTo(PurchaseOrderStatus::Cancelled)) {
            throw PurchaseOrderTransitionNotAllowed::make($from, PurchaseOrderStatus::Cancelled);
        }

        $order->status = PurchaseOrderStatus::Cancelled;
        $order->save();

        return $order;
    }
}
