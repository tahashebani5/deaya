<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Inventory\Actions\RevalueStockBatch;
use App\Support\Exceptions\DomainException;

/**
 * A cost layer with nothing left on the shelf cannot be repriced.
 *
 * **Refused rather than allowed as a no-op, because the request means something and the answer
 * is no.** {@see RevalueStockBatch} is prospective: it changes what
 * the *remaining* stock is carried at, and every unit already drawn recorded its cost in
 * `stock_batch_consumptions` at the moment it left. A layer at zero has no remainder to correct,
 * so accepting the edit would change a number that nothing will ever read again while appearing
 * to fix the orders it was drawn into — which it cannot, and must not.
 */
final class BatchIsFullyConsumed extends DomainException
{
    public static function make(int $batchId): self
    {
        return new self(
            "الدفعة رقم {$batchId} صُرفت بالكامل — لم يبقَ منها شيء لتعديل تكلفته"
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['unit_cost' => [$this->getMessage()]];
    }
}
