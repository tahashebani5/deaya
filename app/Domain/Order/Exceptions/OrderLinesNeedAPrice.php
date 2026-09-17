<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A request cannot be accepted while any of its lines is still waiting to be quoted.
 *
 * **The twin of {@see OutsourcedOrderNeedsAVendor}, and it guards the same seam.** A request from
 * the app is allowed to be incomplete in exactly the ways the customer could not complete: they
 * do not know which vendor makes their bags, and they are never shown a price for a product the
 * catalogue prices «حسب الطلب». An *order* may be incomplete in neither. «جديدة» means verified,
 * and an order that reached it unpriced would invoice for less than the goods are worth with
 * nothing on the row able to say so.
 *
 * The lines are named rather than counted: a request for three sizes refused with «بعض الأسطر
 * تحتاج سعراً» leaves the reviewer hunting for which.
 */
final class OrderLinesNeedAPrice extends DomainException
{
    /**
     * @param  list<string>  $lines
     */
    public static function make(array $lines): self
    {
        return new self(
            'لا يمكن قبول الطلب قبل تسعير كل سطر — ينقص سعر: '.implode('، ', $lines),
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
