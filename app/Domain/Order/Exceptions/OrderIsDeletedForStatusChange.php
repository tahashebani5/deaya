<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A status move arriving at an order that is already in the archive.
 *
 * Named for its caller in the manner of {@see OrderIsCancelledForPayment}, because «الطلبية
 * محذوفة» means something different to each door it is said at: here it is a *move* being
 * refused, and the way out is to restore the order first.
 *
 * **The race this closes is the worst one in the feature.** A delete and a forward move can both
 * bind a live order and both succeed: the delete archives it and hands the goods back, while
 * `ChangeOrderStatus` — which asks `isFinal()` and nothing about `deleted_at` — walks on into
 * «جاهزة للطباعة», takes 300 bags out of the warehouse for an order that no longer appears in
 * any list, and dispatches `OrderStockDrawn` so an investor is paid for them. No index catches
 * it: a deduction has no reversal to collide with. The guard is asked under the same row lock
 * `DeleteOrder` takes, so the second of the two waits and then reads the truth.
 */
final class OrderIsDeletedForStatusChange extends DomainException
{
    public static function make(string $code): self
    {
        return new self("الطلبية «{$code}» محذوفة — استعِدها من الأرشيف قبل تغيير حالتها");
    }
}
