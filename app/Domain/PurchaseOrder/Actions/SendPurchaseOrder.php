<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Actions;

use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrder\Exceptions\PurchaseOrderNeedsAtLeastOneItem;
use App\Domain\PurchaseOrder\Exceptions\PurchaseOrderTransitionNotAllowed;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;

/**
 * Marks a purchase order as sent to its vendor — the manual half of reaching
 * {@see PurchaseOrderStatus::Arrived}, the other half being the first shipment posting against
 * it directly (see {@see ReceivePurchaseOrder}).
 *
 * @throws PurchaseOrderTransitionNotAllowed
 * @throws PurchaseOrderNeedsAtLeastOneItem
 */
final class SendPurchaseOrder
{
    public function __invoke(PurchaseOrder $order): PurchaseOrder
    {
        $from = $order->status;

        if (! $from->canMoveTo(PurchaseOrderStatus::Arrived)) {
            throw PurchaseOrderTransitionNotAllowed::make($from, PurchaseOrderStatus::Arrived);
        }

        // Nothing for the vendor to fulfil and nothing for a shipment to ever match against.
        if (! $order->items()->exists()) {
            throw PurchaseOrderNeedsAtLeastOneItem::make();
        }

        $order->status = PurchaseOrderStatus::Arrived;
        $order->save();

        return $order;
    }
}
