<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderPaymentType;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\CarrierSettlementExceedsRemaining;
use App\Domain\Order\Exceptions\OrderIsCancelledForWriteOff;
use App\Domain\Order\Exceptions\PaymentAmountMustBePositive;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderPayment;
use App\Domain\Order\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The part of the bill the customer paid to the courier rather than to us.
 *
 * **The delivery fee, and only ever the delivery fee.** We subtract `orders.delivery_price` from
 * the COD before handing a parcel to Nawris, so the courier collects it at the door on their own
 * account — see NAWRIS-INTEGRATION.md §5.2. The order is then owed a sum no cash of ours will ever
 * close, and «تم التسوية» refuses a debt. This is what closes it.
 *
 * **Neither a payment nor a write-off, and the ledger needed a third type to say so.** Recording
 * it as a payment would put money in the drawer report that never reached the drawer; recording it
 * as a write-off would post a loss for every parcel we ship, when nothing was lost — the customer
 * paid in full and part of it went elsewhere by arrangement. See
 * {@see OrderPaymentType::CarrierSettled}.
 *
 * **The ceiling matters more here than anywhere else in the ledger, because no human is holding
 * the pen.** Every other credit entry is typed by somebody who can see the order; this one is
 * written by a webhook, and a repeated delivery notice is routine traffic. The row is locked for
 * the whole check-and-write for the same reason `RecordOrderPayment` locks it — a clerk collecting
 * at the counter at the same instant would otherwise let the two of them close more than was owed.
 *
 * A reason is required, exactly as {@see WriteOffOrderBalance} requires one: an entry that takes a
 * debt off the books owes the next reader an explanation. Undone by
 * {@see ReverseOrderPayment}, which routes the reversal back to the right total through
 * {@see OrderPayment::affectsCarrierSettlement()}.
 */
final class RecordCarrierSettlement
{
    public function __construct(private readonly RecalculateOrderPayments $recalculate) {}

    /**
     * @throws PaymentAmountMustBePositive
     * @throws OrderIsCancelledForWriteOff
     * @throws CarrierSettlementExceedsRemaining
     */
    public function __invoke(
        Order $order,
        string $amount,
        string $reason,
        ?User $actor = null,
    ): OrderPayment {
        // Needs nothing from the database, so it is settled before a row is held.
        if (bccomp($amount, '0', Money::SCALE) <= 0) {
            throw PaymentAmountMustBePositive::make($amount);
        }

        return DB::transaction(function () use ($order, $amount, $reason, $actor): OrderPayment {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            // A written-off order is refused here for the reason a write-off is: there is no debt
            // on a cancelled order for a courier to have collected against.
            if ($locked->status === OrderStatus::Cancelled) {
                throw OrderIsCancelledForWriteOff::make((string) $locked->code);
            }

            // Read under the lock, and floored: on an overpaid order the remainder is negative,
            // and closing a negative debt is not a sentence. That order needs a refund.
            $remaining = $locked->remainingAmount();
            $remaining = bccomp($remaining, '0', Money::SCALE) < 0 ? '0.00' : $remaining;

            if (bccomp($amount, $remaining, Money::SCALE) > 0) {
                throw CarrierSettlementExceedsRemaining::make($amount, $remaining);
            }

            $entry = new OrderPayment([
                'amount' => $amount,
                // No method: no money of ours moved, in either direction. The table's CHECK
                // refuses a carrier settlement that names one.
                'method' => null,
                'reference' => null,
                // The moment we learned of it, never a date somebody types — the same rule a
                // write-off follows, and for the same reason: nothing here moved on a day of its
                // own that we could honestly back-date to.
                'paid_at' => now(),
                'notes' => $reason,
            ]);

            $entry->order_id = $locked->getKey();
            $entry->type = OrderPaymentType::CarrierSettled;
            $entry->recorded_by = $actor?->getKey();

            $entry->save();

            ($this->recalculate)($locked);

            return $entry;
        });
    }
}
