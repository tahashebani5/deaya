<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Inventory\DTOs\StockMovementData;
use App\Support\Exceptions\DomainException;

/**
 * Stock is about to leave a warehouse for this order, and there is nobody to attribute it to.
 *
 * `ChangeOrderStatus` accepts a nullable `?User $actor` — a console command or an importer may
 * move an order with nobody behind it — but {@see StockMovementData}
 * requires a real employee id for every movement, the same way `StockMovement::employee_id` is
 * never nullable. In practice every HTTP request that can reach `ready` carries an
 * authenticated user; this exists so the rare exception fails with a readable message instead of
 * a `TypeError` on a null passed where an id was expected.
 */
final class FulfillmentRequiresAnActor extends DomainException
{
    public static function make(): self
    {
        return new self('لا يمكن خصم المخزون دون تسجيل الموظف الذي نفّذ العملية');
    }
}
