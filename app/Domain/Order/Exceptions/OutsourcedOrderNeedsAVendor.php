<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A وسيط order with nobody named to make it.
 *
 * **Thrown from the domain rather than checked in the request**, and that is not a stylistic
 * choice: whether an order owes a vendor depends on the road it walks, and the road is read off
 * its lines — so the question cannot be answered until the lines are in the database. `CreateOrder`
 * asks it immediately after `ResolveOrderFlow`, inside the same transaction, so an order that
 * should have named a vendor is never left half-taken.
 */
final class OutsourcedOrderNeedsAVendor extends DomainException
{
    public static function make(): self
    {
        return new self('الطلبية الوسيطة تحتاج مورداً — اختر المورد الذي سينفّذها');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['vendor_id' => [$this->getMessage()]];
    }
}
