<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Actions\ConfirmDepositReceipt;
use App\Domain\Order\Models\Order;
use App\Support\Exceptions\DomainException;

/**
 * Somebody confirmed a عربون on an order that never asked for one.
 *
 * The tick means «رأيتُ العربون في الحساب», so there has to *be* a عربون — a figure the shop
 * named when it parked the order in «انتظار العربون». Without one there is nothing the
 * confirmation could be about, and a flag set on an order that never had a deposit would put a
 * row in the accountant's queue that nothing explains.
 *
 * Read from `deposit_expected_amount` rather than from the status — see
 * {@see Order::asksForADeposit()} — because the question outlives the two deposit statuses: an
 * order confirmed after it has already shipped is the ordinary case, not an edge one.
 *
 * The app is told the same thing by `can_confirm_deposit`, so this is the guard behind a box that
 * was never drawn rather than a refusal anybody should meet. See {@see ConfirmDepositReceipt}.
 */
final class DepositConfirmationNeedsADeposit extends DomainException
{
    public static function make(): self
    {
        return new self('لا يوجد عربون على هذه الطلبية لتأكيد استلامه');
    }
}
