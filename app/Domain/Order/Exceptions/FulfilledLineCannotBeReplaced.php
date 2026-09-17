<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A line whose bags have already left the shelf cannot be swapped for a different line.
 *
 * `Order::itemsAreEditable()` reopens the lines at «قيد الطباعة», which is *after* the stock
 * leaves at «جاهزة للطباعة». Replacing the set there soft-deletes rows carrying a
 * `fulfillment_stock_movement_id`, and `ReverseOrderStockDeduction` iterates `$order->items` —
 * which excludes trashed rows — so the draw was never credited back and the bags left the
 * building without a record.
 *
 * The correction that *is* sanctioned is untouched: reach «جاهزة» with a corrected
 * `warehouse_quantity` and `RestateOrderStockDeduction` reverses the draw in full and posts a
 * fresh one at the right amount.
 */
final class FulfilledLineCannotBeReplaced extends DomainException
{
    public static function make(string $variantLabel): self
    {
        return new self(
            "لا يمكن استبدال بنود الطلبية بعد خروج البضاعة من المخزن — البند «{$variantLabel}» "
            .'خُصمت كميته. لتصحيح الكمية انقل الطلبية إلى «جاهزة» وصحّح الكمية المسحوبة.'
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['items' => [$this->getMessage()]];
    }
}
