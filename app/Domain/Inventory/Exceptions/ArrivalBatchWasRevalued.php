<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Inventory\Actions\RevalueStockBatch;
use App\Domain\Inventory\Actions\WithdrawArrivalStockBatches;
use App\Support\Exceptions\DomainException;

/**
 * A cost layer somebody has repriced by hand is not withdrawn silently.
 *
 * {@see RevalueStockBatch} is a deliberate act behind its own grant — someone looked at what this
 * stock was carried at, decided it was wrong, and corrected it. Undoing the receipt would delete
 * that judgement along with the layer, and the person who made it would never learn that it had
 * gone. Refused rather than merged: if the receipt really was an error then so was the
 * revaluation, and saying so out loud is one conversation rather than a number quietly vanishing.
 *
 * Not overridable by the manager grant, for the same reason {@see ArrivalBatchAlreadyDrawnOn} is
 * not: what this protects is somebody else's work, not the clock.
 *
 * @see WithdrawArrivalStockBatches
 */
final class ArrivalBatchWasRevalued extends DomainException
{
    public static function make(int $batchId): self
    {
        return new self(
            "لا يمكن التراجع عن الاستلام: عُدِّلت تكلفة الدفعة رقم {$batchId} يدوياً بعد استلامها"
        );
    }
}
