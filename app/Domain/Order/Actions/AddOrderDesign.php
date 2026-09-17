<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Customer\CustomerService;
use App\Domain\Order\Enums\OrderDesignStatus;
use App\Domain\Order\Exceptions\DesignDoesNotBelongToCustomer;
use App\Domain\Order\Exceptions\OrderDesignsAreLocked;
use App\Domain\Order\Exceptions\OrderIsClosed;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderDesign;

/**
 * Puts the next version of the artwork in front of the customer.
 *
 * The design comes from the customer's own library and is *pointed at*, not copied: that table
 * never deletes a file and a different file is a different row by force of its checksum index,
 * so the reference can neither dangle nor change underneath a finished order.
 *
 * **The version number is allocated, not counted.** `max(version) + 1` rather than
 * `count() + 1`, so removing version 2 does not make the next one a second version 3 — and
 * «التصميم الثالث» keeps meaning the file it meant in the conversation.
 */
final class AddOrderDesign
{
    public function __construct(private readonly CustomerService $customers) {}

    /**
     * @throws OrderIsClosed
     * @throws OrderDesignsAreLocked
     * @throws DesignDoesNotBelongToCustomer
     */
    public function __invoke(Order $order, int $customerDesignId, ?string $notes = null): OrderDesign
    {
        if ($order->status->isClosed()) {
            throw OrderIsClosed::make($order->status);
        }

        // Read from the order's *current* status, which is why {@see ChangeOrderStatus} decides
        // per move whether to attach before or after writing it: a move *into* design arrives
        // here with the order already in design, and a move out of it arrives here before the
        // order has left. Either way the answer to this question is yes at the moment it is put.
        if (! $order->designsAreEditable()) {
            throw OrderDesignsAreLocked::make($order->status);
        }

        $design = $this->customers->findDesign($customerDesignId);

        if ((int) $design->customer_id !== (int) $order->customer_id) {
            throw DesignDoesNotBelongToCustomer::make(
                (int) $design->getKey(),
                (int) $order->customer_id,
            );
        }

        // withTrashed: a removed version still used its number, and reusing it would collide
        // with the partial unique index the moment the removed row were restored.
        $nextVersion = (int) $order->designs()->withTrashed()->max('version') + 1;

        /** @var OrderDesign $orderDesign */
        $orderDesign = $order->designs()->make([
            'customer_design_id' => $design->getKey(),
            'notes' => $notes,
        ]);

        // Force-filled *before* the insert, not after: both columns are NOT NULL, and neither
        // may come from a request — the version is allocated here and the status is where every
        // version starts.
        $orderDesign->forceFill([
            'version' => $nextVersion,
            'status' => OrderDesignStatus::Proposed,
        ])->save();

        return $orderDesign->refresh();
    }
}
