<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Order\DTOs\OrderPaymentData;
use App\Domain\Order\Enums\OrderPaymentType;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\OrderIsCancelledForPayment;
use App\Domain\Order\Exceptions\PaymentAmountMustBePositive;
use App\Domain\Order\Exceptions\PaymentExceedsRemaining;
use App\Domain\Order\Exceptions\ReceiptRequiredForMethod;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderPayment;
use App\Domain\Order\Support\Money;
use App\Support\Media\StoreReceipt;
use Illuminate\Support\Facades\DB;

/**
 * Money in.
 *
 * **The order row is locked for the whole check-and-write**, and that lock is the reason this
 * action is more than three lines. Reading what is outstanding and then writing an entry against
 * it is the textbook lost update: two clerks taking 300 each against an order that owes 300
 * would both read «متبقٍ ٣٠٠», both find their payment fits, and both commit — leaving an order
 * paid 600 that no single request did anything wrong to produce. `lockForUpdate` makes the
 * second wait, see the first's total, and correctly refuse. Same reasoning as
 * {@see ApplyStockChange} on a shelf balance.
 *
 * The entry and the recalculated total are written in one transaction: either the ledger gains a
 * row and `paid_amount` moves to match, or neither happens. A total that outlived its entry
 * would be the exact failure this design exists to make impossible.
 */
final class RecordOrderPayment
{
    public function __construct(
        private readonly RecalculateOrderPayments $recalculate,
        private readonly StoreReceipt $storeReceipt,
    ) {}

    public function __invoke(Order $order, OrderPaymentData $data, ?User $actor = null): OrderPayment
    {
        if (bccomp($data->amount, '0', Money::SCALE) <= 0) {
            throw PaymentAmountMustBePositive::make($data->amount);
        }

        // Checked before the lock is taken: it needs nothing from the database, and holding a
        // row while deciding that a form was incomplete buys nothing.
        if ($data->method->requiresReceipt() && $data->receipt === null) {
            throw ReceiptRequiredForMethod::make($data->method);
        }

        return DB::transaction(function () use ($order, $data, $actor): OrderPayment {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            // The one closed door, and only for money coming *in*: there is nothing left to pay
            // for. Refunds stay open on a cancelled order — see the exception's own note.
            if ($locked->status === OrderStatus::Cancelled) {
                throw OrderIsCancelledForPayment::make((string) $locked->code);
            }

            $remaining = $this->remaining($locked);

            if (bccomp($data->amount, $remaining, Money::SCALE) > 0) {
                throw PaymentExceedsRemaining::make($data->amount, $remaining);
            }

            $payment = $this->write($locked, $data, $actor);

            ($this->recalculate)($locked);

            return $payment;
        });
    }

    /**
     * What is still owed, floored at zero.
     *
     * Floored rather than left negative so that an order already overpaid — which happens when a
     * discount lands after payment, not because anyone erred — refuses further payments with
     * «المتبقي ٠.٠٠» instead of an amount below zero that reads like a system fault.
     *
     * **Asked of the order rather than subtracted here**, so that what may be collected and what
     * is displayed as owed are the same number. They parted company the day a debt could also be
     * closed by writing it off: an order of 110 with 5 forgiven owes 105, and a private
     * `grand_total - paid_amount` here would have gone on accepting 110.
     */
    private function remaining(Order $order): string
    {
        $remaining = $order->remainingAmount();

        return bccomp($remaining, '0', Money::SCALE) < 0 ? '0.00' : $remaining;
    }

    /**
     * Assigned rather than mass-assigned for the three that decide what this entry *is*: a
     * payload that could set the type could turn a collection into a refund, and one that could
     * set `recorded_by` could put a colleague's name on it. See RULES.md §9.4.
     */
    private function write(Order $order, OrderPaymentData $data, ?User $actor): OrderPayment
    {
        $payment = new OrderPayment([
            'amount' => $data->amount,
            'method' => $data->method,
            'reference' => $data->reference,
            'paid_at' => $data->paidAt,
            'notes' => $data->notes,
        ]);

        $payment->order_id = $order->getKey();
        $payment->type = OrderPaymentType::Payment;
        $payment->recorded_by = $actor?->getKey();

        if ($data->receipt !== null) {
            // forceFill, because the five receipt columns are not fillable: a payload that could
            // set `receipt_path` could claim a receipt exists at a path of its choosing. What is
            // written here is what the disk actually accepted.
            $payment->forceFill(($this->storeReceipt)("payment-receipts/{$order->getKey()}", $data->receipt));
        }

        $payment->save();

        return $payment;
    }
}
