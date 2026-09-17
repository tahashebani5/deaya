<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * This order is already out with the carrier.
 *
 * **The guarantee the schema deliberately does not make.** The unique key is
 * `(parcel_id, order_id)` rather than `order_id` alone, so the database allows an order to appear
 * in several parcels over its life — which is what keeps the dispatch history when a returned
 * parcel goes out again. "At most one *open* parcel" is the rule that actually matters, and it is
 * enforced here, against `closed_at`.
 */
final class OrderAlreadyHasAnOpenParcel extends DomainException
{
    public static function make(string $orderCode, ?string $parcelCode): self
    {
        $named = $parcelCode !== null ? " (الطرد {$parcelCode})" : '';

        return new self("الطلبية {$orderCode} مُسلَّمة لنورس بالفعل{$named}");
    }
}
