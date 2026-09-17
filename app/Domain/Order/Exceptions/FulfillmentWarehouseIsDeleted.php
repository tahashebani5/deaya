<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * The warehouse this order drew on has been retired, so neither giving the goods back nor taking
 * them out again may go ahead.
 *
 * **Checked explicitly because the foreign key does not fire and the failure downstream is worse
 * than a crash.** `orders.fulfillment_warehouse_id` is declared `nullOnDelete`, but
 * `DeleteWarehouse` soft deletes — nothing is really removed, so the column keeps pointing at a
 * warehouse the API says does not exist.
 *
 * What each side would otherwise do:
 *
 * - **The credit-back** would reach `ApplyStockChange::growBalance()`, which happily opens a
 *   **new, live** `warehouse_stocks` row inside a retired warehouse. Real stock, in a place no
 *   screen lists and no order can draw on — silent, and only found by someone counting.
 * - **The re-deduction** would find `lockedRow()` empty and throw
 *   `InsufficientStock('0.000', …)`, telling the storekeeper every size reads zero when the
 *   truth is that the shelf itself is gone.
 *
 * Both are answered here, by name, before anything moves.
 */
final class FulfillmentWarehouseIsDeleted extends DomainException
{
    public static function make(string $code): self
    {
        return new self(
            "المخزن الذي خرجت منه بضاعة الطلبية «{$code}» لم يعد موجوداً — أعِد المخزن أوّلاً، أو صحّح الرصيد بجرد"
        );
    }
}
