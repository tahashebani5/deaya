<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Models\OrderPayment;
use App\Domain\Order\Support\TransitionFields;
use App\Support\Exceptions\DomainException;

/**
 * A status change is carrying money, and there is nobody to put on the entry.
 *
 * Same shape as {@see FulfillmentRequiresAnActor}, and the same reason: `ChangeOrderStatus`
 * accepts a nullable `?User $actor` because a console command may move an order with nobody
 * behind it, while {@see OrderPayment::$recorded_by} exists so that no collection is ever
 * anonymous — «من قبض هو من يسجّل» is the rule the whole ledger is built on.
 *
 * In practice unreachable from HTTP: {@see TransitionFields} offers the money box only to a
 * signed-in user holding `orders.payments.record`, so a payload carrying an amount has already
 * been through a permission check that a null actor could not pass. This exists so the rare
 * caller that skips that layer fails with a sentence rather than writing an entry nobody owns.
 */
final class PaymentRequiresAnActor extends DomainException
{
    public static function make(): self
    {
        return new self('لا يمكن تسجيل دفعة دون تسجيل الموظف الذي قبضها');
    }
}
