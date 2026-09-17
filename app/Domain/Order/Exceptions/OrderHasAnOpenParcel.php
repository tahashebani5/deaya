<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * An order still out with نورس may not be deleted until the parcel is settled.
 *
 * **Refused because the loss would be silent, which is what makes this stricter than the money
 * rule beside it.** `ApplyNawrisStatus::applyToOrder()` walks `$parcel->orders`, a relation the
 * soft-delete scope trims from the order's end — so an archived order empties the loop. The
 * status never moves, the collection is never recorded, and `closed_at` is never written
 * **because it is written inside that loop**. The parcel stays «ما زال في الطريق» for ever, and
 * the webhook that delivered the news is logged as handled, so it never appears in the
 * «وصل ولم يُعالَج» queue either. Money and a delivery vanish with nothing anywhere saying so.
 *
 * Detaching or cancelling the parcel closes it, and either is a decision a person makes — so
 * that is what the message asks for rather than doing it here.
 */
final class OrderHasAnOpenParcel extends DomainException
{
    public static function make(string $code, string $parcelCode): self
    {
        return new self(
            "لا يمكن حذف الطلبية «{$code}» وطردها «{$parcelCode}» ما زال مفتوحاً لدى نورس — افصل الطرد أو ألغِه أولاً"
        );
    }
}
