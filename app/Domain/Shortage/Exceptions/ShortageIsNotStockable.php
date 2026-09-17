<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Exceptions;

use App\Domain\Shortage\Models\Shortage;
use App\Support\Exceptions\DomainException;

/**
 * There is no shelf for a roll of tape.
 *
 * A shortage written down by hand for something the catalogue has never heard of carries no size,
 * so it resolves to no stock item and there is nothing for an arrival to post against. Naming a
 * warehouse on one is a request the ledger cannot honour, and accepting it silently would leave
 * the money row claiming an arrival that never happened.
 *
 * The purchase is still recorded — quantity, amount and method — it simply moves no stock. See
 * {@see Shortage::isStockable()} and SHORTAGES-DESIGN §٧٫٥.
 */
final class ShortageIsNotStockable extends DomainException
{
    public static function make(): self
    {
        return new self('هذا النقص ليس صنفاً في المخزون — تُسجَّل قيمته بلا إدخال مخزني');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['warehouse_id' => [$this->getMessage()]];
    }
}
