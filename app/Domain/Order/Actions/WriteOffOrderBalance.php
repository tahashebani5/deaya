<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderPaymentType;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\OrderIsCancelledForWriteOff;
use App\Domain\Order\Exceptions\PaymentAmountMustBePositive;
use App\Domain\Order\Exceptions\SettlementRequiresFullPayment;
use App\Domain\Order\Exceptions\WriteOffExceedsRemaining;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderPayment;
use App\Domain\Order\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Money that is not coming, closed on the record.
 *
 * **The five dinars.** An order of 110 comes back from the courier with 105 in the envelope and
 * the business decides the rest is not worth chasing. Until this existed there were only two
 * ways to finish that order, and both were wrong: type a payment of five nobody received, or
 * leave it standing in «تم الاستلام» for ever because «تم التسوية» refuses a debt — see
 * {@see SettlementRequiresFullPayment}. The first is a lie in a ledger built to be believed; the
 * second is a queue that fills with orders nobody can act on.
 *
 * **Not a discount, and the difference is the whole design.** A discount would edit the invoice
 * down to 105 — rewriting what the customer was actually billed, months after they were billed
 * it, and quietly erasing the fact that anything went wrong. That door is shut anyway once the
 * bags are delivered (`UpdateOrder` refuses a closed order), and shut for a good reason. This
 * leaves `grand_total` exactly as it was and records the gap *as a gap*, so it can be counted
 * later as what it is: a loss.
 *
 * **Not a payment either**, which is why it moves `written_off_amount` rather than
 * `paid_amount`. Whoever asks «كم قبضنا اليوم؟» must never be handed a number containing money
 * that never arrived.
 *
 * A reason is required, the same bar {@see ReverseOrderPayment} sets and for the same reason: an
 * entry that takes a debt off the books owes the next reader an explanation. And like every
 * other entry here it is written once and never edited — a write-off decided in error is undone
 * by reversing it, which puts the debt back exactly where it was.
 *
 * The order row is locked for the whole check-and-write, as `RecordOrderPayment` explains: two
 * accountants forgiving the same 5 at the same instant would both read «متبقٍ ٥», both find
 * their entry fits, and between them write off 10 that was never owed.
 */
final class WriteOffOrderBalance
{
    public function __construct(private readonly RecalculateOrderPayments $recalculate) {}

    /**
     * @throws PaymentAmountMustBePositive
     * @throws OrderIsCancelledForWriteOff
     * @throws WriteOffExceedsRemaining
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

            if ($locked->status === OrderStatus::Cancelled) {
                throw OrderIsCancelledForWriteOff::make((string) $locked->code);
            }

            // Read under the lock, and floored: on an overpaid order the remainder is negative,
            // and «you may forgive -50» is not a sentence. Nothing may be written off there,
            // which is right — that order needs a refund, not forgiveness.
            $remaining = $locked->remainingAmount();
            $remaining = bccomp($remaining, '0', Money::SCALE) < 0 ? '0.00' : $remaining;

            if (bccomp($amount, $remaining, Money::SCALE) > 0) {
                throw WriteOffExceedsRemaining::make($amount, $remaining);
            }

            $entry = new OrderPayment([
                'amount' => $amount,
                // No method: no money moved, in either direction. The table's CHECK refuses a
                // write-off that names one.
                'method' => null,
                'reference' => null,
                // **The moment the decision was taken, and never a date somebody types.** A
                // payment may be back-dated because the money genuinely moved on Thursday; a
                // write-off did not move, and letting one be dated into a closed period would
                // hand somebody a way to put this month's loss in last month's books.
                'paid_at' => now(),
                'notes' => $reason,
            ]);

            $entry->order_id = $locked->getKey();
            $entry->type = OrderPaymentType::WriteOff;
            $entry->recorded_by = $actor?->getKey();

            $entry->save();

            ($this->recalculate)($locked);

            return $entry;
        });
    }
}
