<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Order\Exceptions\DepositConfirmationNeedsADeposit;
use App\Domain\Order\Exceptions\DepositConfirmationNeedsASecondPerson;
use App\Domain\Order\Models\Order;

/**
 * Records that somebody checked, and the عربون is really there — or takes that back.
 *
 * **The only writer of `is_deposit_received`.** Nothing derives it: not the status, not the
 * ledger, not `RecalculateOrderPayments`. Moving an order to «عربون مدفوع» is the counter saying
 * the customer paid; this is a second person saying they looked at the account and the money is
 * in it. Two different claims by two different people, which is why they are two different
 * columns and two different permissions.
 *
 * **Three columns written together, and cleared together.** A tick without a name says the check
 * was done by nobody and one without a stamp says it was done at no particular time — the same
 * reasoning {@see MarkReadyMessageSent} was built on, and this is deliberately its twin: one
 * action for both directions, because the untick is the correction of a stray tap on the tick and
 * splitting them would be two classes enforcing one rule.
 *
 * **It gates nothing.** No status change, no production step, no dispatch and no settlement reads
 * the flag. An order whose deposit nobody has confirmed yet prints, ships and is delivered
 * exactly like one whose deposit was confirmed — bookkeeping that stops a press is bookkeeping
 * nobody will do, and the person who could unblock it is by design not in the room. What the flag
 * feeds is a queue: «عربون أُعلن ولم يُؤكَّد», which is the accountant's own work list.
 *
 * **And it may disagree with the ledger.** Somebody can confirm a deposit no payment was ever
 * recorded for, or confirm one whose payment is reversed afterwards. That is inherent to an
 * attestation rather than a flaw in it: the tick answers «هل وصل المال فعلاً؟» and the ledger
 * answers «هل سُجِّل؟», and the gap between those two questions is the accountant's actual job.
 * The order screen shows the disagreement rather than the server preventing it — see
 * Docs/orders/ORDER-DEPOSIT-PLAN.md §٣٫٥.
 */
final class ConfirmDepositReceipt
{
    /**
     * @param  bool  $received  true stamps the confirmation and the actor; false clears both.
     *
     * @throws DepositConfirmationNeedsADeposit
     * @throws DepositConfirmationNeedsASecondPerson
     */
    public function __invoke(Order $order, bool $received, ?User $actor = null): Order
    {
        // Guarded here rather than only in the request, so a console command and a future import
        // meet the same rule — the reason every other order rule lives in the domain.
        if (! $order->asksForADeposit()) {
            throw DepositConfirmationNeedsADeposit::make();
        }

        // **Only ticking is guarded.** Clearing is open to anybody holding the grant, the claimer
        // included: it withdraws a statement rather than making one, and a confirmation nobody
        // may take back is worse than one anybody may.
        if ($received && $actor !== null && $order->deposit_claimed_by !== null
            && (int) $order->deposit_claimed_by === (int) $actor->getKey()) {
            throw DepositConfirmationNeedsASecondPerson::make();
        }

        // `forceFill`: none of the three is fillable, deliberately. A request that could post
        // them could put a colleague's name against a check they never made.
        $order->forceFill([
            'is_deposit_received' => $received,
            'deposit_confirmed_at' => $received ? now() : null,
            'deposit_confirmed_by' => $received ? $actor?->getKey() : null,
        ])->save();

        return $order;
    }
}
