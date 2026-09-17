<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Actions;

use App\Domain\Shortage\Enums\ShortageStatus;
use App\Domain\Shortage\Models\Shortage;
use App\Domain\Shortage\Models\ShortageSupply;
use App\Domain\Shortage\Support\Money;

/**
 * Restates what has been supplied and what it cost, and closes the shortage when nothing is left.
 *
 * **The only writer of `supplied_quantity`, `total_paid` and «مكتمل».** All three are derived
 * from `shortage_supplies`, and deriving them in one place is what keeps the cache and the ledger
 * from coming apart — the `RecalculateOrderPayments` arrangement, for the same reason.
 *
 * **Restated from the ledger, never adjusted by a delta.** Adding the new row's quantity to the
 * column would be one statement shorter and wrong in the case this feature is mostly about: a
 * reversal has to take a quantity back out, a second reversal must not take it out twice, and a
 * shortage whose column drifted by a rounding error would never reach zero and so would never
 * close. Summing what is actually there cannot drift, and running this twice is a no-op.
 *
 * **The status is decided here, and this is the only place «مكتمل» is ever written.** It is not
 * in any transition map — see {@see ShortageStatus} — because «لا يتحوّل النقص إلى مكتمل إلا بعد
 * توفير كامل الكمية» is a rule about arithmetic. A clerk who could pick it from a dropdown could
 * close a shortage with twenty kilos still missing.
 *
 * **And it un-closes.** A reversal that puts quantity back must reopen what it reopened: a
 * shortage left reading «مكتمل» with ten kilos outstanding is worse than one that never closed,
 * because nobody is looking at it any more. Where it lands is «جاري البحث» rather than «جديد» —
 * the chase has demonstrably started, and sending it back to «جديد» would tell the board that
 * nobody has touched it.
 *
 * Called inside the caller's transaction, always. Every caller writes a row and then calls this,
 * and a row written without its restatement is exactly the drift above.
 */
final class RecalculateShortageTotals
{
    public function __invoke(Shortage $shortage): Shortage
    {
        $paid = '0';

        foreach ($shortage->liveSupplies() as $supply) {
            /** @var ShortageSupply $supply */
            // Null on a `resolved_externally` row: goods that arrived from the order were paid
            // for on a purchase order, and counting a zero for them here is harmless while
            // counting anything else would be a purchase nobody made.
            $paid = bcadd($paid, (string) ($supply->amount ?? '0'), 8);
        }

        $shortage->forceFill([
            // Through the model's own definition, so this and the sync cannot come to different
            // answers about what has come back — see `liveSuppliedQuantity()`.
            'supplied_quantity' => $shortage->liveSuppliedQuantity(),
            'total_paid' => Money::round($paid),
        ]);

        $shortage->forceFill(['status' => $this->statusFor($shortage)])->save();

        return $shortage;
    }

    /**
     * Where the arithmetic puts a shortage, given what the employee had chosen before it ran.
     *
     * Three of the four answers are "leave it alone". This deliberately does not touch «جديد» or
     * «غير متوفر» on a shortage that is still short: a supply *reopening* an abandoned shortage
     * is {@see RecordShortageSupply}'s decision to make, taken before this runs, because it is a
     * statement about the chase rather than about the numbers.
     */
    private function statusFor(Shortage $shortage): ShortageStatus
    {
        if ($shortage->isFullySupplied()) {
            return ShortageStatus::Completed;
        }

        // Still short, and last seen closed — a reversal has just put quantity back. See the
        // class docblock for why this lands on «جاري البحث».
        if ($shortage->status === ShortageStatus::Completed) {
            return ShortageStatus::Searching;
        }

        return $shortage->status;
    }
}
