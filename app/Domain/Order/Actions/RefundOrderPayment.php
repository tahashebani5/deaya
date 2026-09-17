<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Order\DTOs\OrderPaymentData;
use App\Domain\Order\Enums\OrderPaymentType;
use App\Domain\Order\Exceptions\PaymentAmountMustBePositive;
use App\Domain\Order\Exceptions\ReceiptRequiredForMethod;
use App\Domain\Order\Exceptions\RefundExceedsPaid;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderPayment;
use App\Domain\Order\Support\Money;
use App\Support\Media\StoreReceipt;
use Illuminate\Support\Facades\DB;

/**
 * Money genuinely handed back.
 *
 * **Not the same operation as reversing an entry, and the difference is the whole reason both
 * exist.** A reversal says a row was a mistake and describes nothing that happened; this says
 * cash left the drawer. They subtract the same figure and answer completely different questions
 * — «كم رددنا للعملاء هذا الشهر؟» is meaningless if every mistyped amount is counted in it.
 *
 * **Allowed in every status, cancelled included.** That is not an oversight: a cancelled order
 * with a deposit against it is the commonest reason to refund anything, and a lock that stopped
 * it would be a bug wearing a safety jacket.
 *
 * **It names no particular payment.** Money going back may have been collected across three of
 * them, and forcing a choice would put a fact in the ledger nobody could stand behind. What
 * bounds it is the order's total paid, which is checked under the same lock the payment path
 * uses and for the same reason.
 */
final class RefundOrderPayment
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

        // The same rule as money coming in, and for a stronger reason: a transfer *out* of the
        // business with no paper behind it is the entry an auditor asks about first.
        if ($data->method->requiresReceipt() && $data->receipt === null) {
            throw ReceiptRequiredForMethod::make($data->method);
        }

        return DB::transaction(function () use ($order, $data, $actor): OrderPayment {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $paid = (string) $locked->paid_amount;

            // We cannot give back what we were never given. Without this, two refunds racing
            // each other would drive the paid total below zero — a debt to the customer, which
            // is a customer account, which this system does not have.
            if (bccomp($data->amount, $paid, Money::SCALE) > 0) {
                throw RefundExceedsPaid::make($data->amount, $paid);
            }

            $refund = $this->write($locked, $data, $actor);

            ($this->recalculate)($locked);

            return $refund;
        });
    }

    private function write(Order $order, OrderPaymentData $data, ?User $actor): OrderPayment
    {
        $refund = new OrderPayment([
            'amount' => $data->amount,
            // A refund has a method for the same reason a payment does: cash back and a transfer
            // back are different events to whoever reconciles the drawer.
            'method' => $data->method,
            'reference' => $data->reference,
            'paid_at' => $data->paidAt,
            'notes' => $data->notes,
        ]);

        $refund->order_id = $order->getKey();
        $refund->type = OrderPaymentType::Refund;
        $refund->recorded_by = $actor?->getKey();

        if ($data->receipt !== null) {
            $refund->forceFill(($this->storeReceipt)("payment-receipts/{$order->getKey()}", $data->receipt));
        }

        $refund->save();

        return $refund;
    }
}
