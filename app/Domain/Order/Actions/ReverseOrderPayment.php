<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderPaymentType;
use App\Domain\Order\Exceptions\EntryCannotBeReversed;
use App\Domain\Order\Exceptions\PaymentAlreadyReversed;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderPayment;
use Illuminate\Support\Facades\DB;

/**
 * Undoing an entry that should never have been written.
 *
 * **This is the answer to "financial entries must be reversible", and it is deliberately not an
 * edit or a delete.** The wrong row stays exactly where it was, and a second row beside it says
 * so and points at it. A clerk reading the ledger a month later sees what was typed, that it was
 * caught, who caught it and when — all of which an `UPDATE` would have erased in the name of
 * tidiness.
 *
 * The reversal carries the **same amount** as its original rather than an amount somebody types.
 * A partial undo is not an undo; if the customer really paid 50 of the 500 that was entered,
 * that is this reversal followed by a payment of 50, and the ledger tells that story correctly.
 *
 * **A reason is required**, which is a stricter rule than a payment's own optional note. The
 * codebase already makes exactly one status transition justify itself — cancelling an order —
 * on the grounds that an action which erases work owes an explanation. Taking money back off an
 * order clears that bar.
 *
 * **It undoes a write-off as readily as a payment**, and lands the amount back on whichever of
 * the order's two totals the original moved — that routing is
 * {@see OrderPayment::affectsWriteOff()}'s, not this action's. A difference forgiven on the
 * wrong order is the same kind of mistake as a figure mistyped at the counter, and the debt it
 * closed comes back exactly as it stood: the order returns to «مدفوعة جزئياً» and «تم التسوية»
 * refuses it again, which is the correct outcome and not a regression.
 */
final class ReverseOrderPayment
{
    public function __construct(private readonly RecalculateOrderPayments $recalculate) {}

    public function __invoke(
        Order $order,
        OrderPayment $payment,
        string $reason,
        ?User $actor = null,
    ): OrderPayment {
        // Read before the transaction: the type is immutable, so nothing can change it under us,
        // and refusing a refund or a reversal here costs no lock at all.
        if (! $payment->type->isCredit()) {
            throw EntryCannotBeReversed::make($payment->type);
        }

        return DB::transaction(function () use ($order, $payment, $reason, $actor): OrderPayment {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            // Checked under the lock, and backed by a partial unique index underneath it. The
            // check gives the readable 422; the index is what actually holds when two requests
            // reverse the same entry at the same instant, because both would pass this line.
            if ($payment->isReversed()) {
                throw PaymentAlreadyReversed::make((int) $payment->getKey());
            }

            $reversal = new OrderPayment([
                'amount' => (string) $payment->amount,
                // No method: no money moved. The table's CHECK refuses a reversal that names one.
                'method' => null,
                'reference' => $payment->reference,
                // The reversal's own moment, not the original's. When the mistake was *caught*
                // is the fact this row exists to record; when the phantom payment was supposedly
                // taken is already on the row it points at.
                'paid_at' => now(),
                'notes' => $reason,
            ]);

            $reversal->order_id = $locked->getKey();
            $reversal->type = OrderPaymentType::Reversal;
            $reversal->reverses_payment_id = $payment->getKey();
            $reversal->recorded_by = $actor?->getKey();

            $reversal->save();

            ($this->recalculate)($locked);

            return $reversal;
        });
    }
}
