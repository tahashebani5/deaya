<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Stock is about to leave a warehouse as scrap, and there is nobody to attribute it to.
 *
 * The same reasoning {@see FulfillmentRequiresAnActor} carries, kept as its own exception rather
 * than a reuse: the two describe different business events even though the underlying gap —
 * `StockMovementData` requires a real employee id — is identical.
 */
final class ScrapRequiresAnActor extends DomainException
{
    public static function make(): self
    {
        return new self('لا يمكن تسجيل تلف دون تسجيل الموظف الذي نفّذ العملية');
    }
}
