<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Order\DTOs\OrderItemData;
use App\Domain\Order\Exceptions\FulfilledLineCannotBeReplaced;
use App\Domain\Order\Exceptions\OrderItemsAreLocked;
use App\Domain\Order\Exceptions\OrderNeedsAtLeastOneItem;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;

/**
 * Replaces an order's lines with the set that was sent, re-pricing each one.
 *
 * Sending `items` replaces the whole set — the same contract a product's variants follow, so a
 * client learns it once. Every line is priced again from the catalogue rather than trusted from
 * the request.
 *
 * **Removals go one at a time.** `$order->items()->delete()` would remove the rows and record
 * nothing, leaving a hole in the history exactly where somebody will look for "who took the
 * 25*35 off this order?". The set is a handful of rows; a few statements is a cheap price for a
 * trail with no gaps.
 *
 * **And a line whose bags already left is not replaceable at all.** `itemsAreEditable()` reopens
 * the set at «قيد الطباعة», which is after the draw at «جاهزة للطباعة» — so the status check
 * above is not enough on its own. Trashing a row that carries a
 * `fulfillment_stock_movement_id` hides it from `ReverseOrderStockDeduction`, which walks
 * `$order->items`, and the stock is then never credited back to the shelf.
 */
final class SyncOrderItems
{
    public function __construct(private readonly AddOrderItem $addItem) {}

    /**
     * @param  list<OrderItemData>  $items
     *
     * @throws OrderItemsAreLocked
     * @throws OrderNeedsAtLeastOneItem
     * @throws FulfilledLineCannotBeReplaced
     */
    public function __invoke(Order $order, array $items): Order
    {
        if (! $order->itemsAreEditable()) {
            throw OrderItemsAreLocked::make($order->status);
        }

        if ($items === []) {
            throw OrderNeedsAtLeastOneItem::make();
        }

        $existing = $order->items()->get();

        $fulfilled = $existing->first(fn (OrderItem $item) => $item->fulfillment_stock_movement_id !== null);

        if ($fulfilled !== null) {
            throw FulfilledLineCannotBeReplaced::make((string) $fulfilled->variant_label);
        }

        $existing->each(fn (OrderItem $item) => $item->delete());

        foreach ($items as $data) {
            ($this->addItem)($order, $data);
        }

        return $order->load('items');
    }
}
