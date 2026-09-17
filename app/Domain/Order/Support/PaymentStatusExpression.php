<?php

declare(strict_types=1);

namespace App\Domain\Order\Support;

use App\Domain\Order\Enums\PaymentStatus;

/**
 * Where an order stands on its money, said in SQL.
 *
 * **There is no `payment_status` column** — see {@see PaymentStatus} for why storing one would
 * rot the first time an order's total moved — so anything the *database* has to decide about
 * payment state has to express the comparison itself.
 *
 * Two callers need that: the list filters on it, and the counts group by it. Written once here
 * so they cannot disagree, which leaves the rule stated in exactly two places — this expression
 * and {@see PaymentStatus::between()} — rather than three. `OrderPaymentStatusFilterTest` walks
 * every state against the enum itself, so the pair cannot drift apart unnoticed.
 *
 * The branches are built from the enum's own cases rather than from literal strings, so renaming
 * a value cannot leave this pointing at a word nothing produces. Their **order matters and
 * mirrors the enum's**: the comparison against the total comes before the one against zero, so
 * an order that costs nothing reads «مدفوعة بالكامل» rather than «غير مدفوعة» and stays out of
 * the queue somebody is meant to chase.
 */
final class PaymentStatusExpression
{
    /** A `CASE` returning one of {@see PaymentStatus}'s wire values for every order row. */
    public static function sql(): string
    {
        $overpaid = PaymentStatus::Overpaid->value;
        $paid = PaymentStatus::Paid->value;
        $writtenOff = PaymentStatus::WrittenOff->value;
        $unpaid = PaymentStatus::Unpaid->value;
        $partial = PaymentStatus::PartiallyPaid->value;

        // What closes a debt is cash, plus what was forgiven, plus what the carrier collected at
        // the door — so the comparison is against all three. Only the forgiven one is told apart
        // afterwards, which is the branch that keeps an order whose shortfall was written off out
        // of «مدفوعة بالكامل»; a carrier settlement deliberately does not get that branch, because
        // the customer did pay in full. Mirrors PaymentStatus::between() exactly.
        return <<<SQL
            CASE
                WHEN paid_amount + written_off_amount + carrier_settled_amount > grand_total THEN '{$overpaid}'
                WHEN paid_amount + written_off_amount + carrier_settled_amount = grand_total AND written_off_amount > 0 THEN '{$writtenOff}'
                WHEN paid_amount + written_off_amount + carrier_settled_amount = grand_total THEN '{$paid}'
                WHEN paid_amount + written_off_amount + carrier_settled_amount <= 0 THEN '{$unpaid}'
                ELSE '{$partial}'
            END
            SQL;
    }
}
