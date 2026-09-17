<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Inventory\Actions\CreditBackStockBatches;
use App\Domain\Inventory\Actions\WithdrawArrivalStockBatches;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\StockBatchConsumption;
use App\Support\Exceptions\DomainException;

/**
 * A receipt whose stock has already moved cannot be undone — by anybody, at any age.
 *
 * **This is the one guard no permission overrides.** The 24-hour window is a discipline rule the
 * business chose and a manager may step past it; this is arithmetic. Withdrawing a layer that has
 * been drawn on would either drive `quantity_remaining` below zero or make the difference up out
 * of somebody else's layer, and the cost of the units that left is already sitting in an order's
 * COGS where no reversal can reach it. Past this point the honest correction is an
 * {@see MovementType::Adjustment} that writes the difference off, not a rewrite of a receipt that
 * demonstrably happened.
 *
 * **Tested against the consumption rows, not against `quantity_remaining`.** A layer drawn for an
 * order that was later cancelled is credited back to that same layer by
 * {@see CreditBackStockBatches}, so its remainder equals what it received again and it looks
 * pristine — while having a real history of movement behind it. {@see StockBatchConsumption} rows
 * are never edited or deleted, so «هل مُسَّت هذه الدفعة يوماً» is the question that has a
 * truthful answer.
 *
 * @see WithdrawArrivalStockBatches
 */
final class ArrivalBatchAlreadyDrawnOn extends DomainException
{
    public static function make(int $batchId): self
    {
        return new self(
            "لا يمكن التراجع عن الاستلام: صُرف من الدفعة رقم {$batchId} — الصواب تسوية جرد لا إلغاء استلام"
        );
    }
}
