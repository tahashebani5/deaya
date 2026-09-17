<?php

declare(strict_types=1);

namespace App\Domain\Order\Enums;

use App\Domain\Order\Models\Order;
use App\Domain\Order\Support\Money;

/**
 * Where an order stands on the money.
 *
 * **Derived, never stored, and that is a deliberate difference from `status`.** An order's
 * workflow status is a decision somebody made and a column is the only honest home for it. Where
 * it stands on its money is arithmetic over three numbers, and all of them move: `grand_total`
 * changes whenever the lines change or a discount is granted, and `paid_amount` and
 * `written_off_amount` whenever the ledger gains a row.
 *
 * A stored column would have to be rewritten by both of those paths, and the first one that
 * forgot would leave an order reading «مدفوعة بالكامل» after its total went up — a wrong answer
 * that looks exactly like a right one. Computing it makes that failure unrepresentable.
 *
 * **A payment axis independent of the workflow axis.** «جاهزة» says nothing about whether the
 * order is paid, and neither implies the other. That separation is why the two were never
 * merged into one enum.
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';

    /** The عربون case: something was paid, not all of it. */
    case PartiallyPaid = 'partially_paid';

    case Paid = 'paid';

    /**
     * More has been paid than the order costs.
     *
     * Not a mistake that got past the guard: `RecordOrderPayment` refuses a payment larger than
     * what is outstanding. This is what a *discount granted after payment* looks like — the
     * total dropped under money already taken, and nobody did anything wrong. It is reported so
     * somebody can refund the difference.
     */
    case Overpaid = 'overpaid';

    /**
     * Nothing is owed any more, and part of what closed it was never collected.
     *
     * **Its own state rather than «مدفوعة بالكامل», because the two are different facts.** An
     * order of 110 that took 105 and had the difference written off owes nothing — so it belongs
     * nowhere near the queue somebody is meant to chase — but saying it was paid in full would
     * be the exact lie the write-off was built to avoid telling. See {@see OrderPaymentType::WriteOff}.
     */
    case WrittenOff = 'written_off';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'غير مدفوعة',
            self::PartiallyPaid => 'مدفوعة جزئياً',
            self::Paid => 'مدفوعة بالكامل',
            self::Overpaid => 'مدفوعة بالزيادة',
            self::WrittenOff => 'مشطوب فرقها',
        };
    }

    /**
     * Whether anything is still owed on it.
     *
     * **A written-off order is not outstanding.** The debt was closed by a decision somebody
     * made and signed, which is a worse outcome than being paid but is not an open balance —
     * and this is the question the settlement guard asks.
     */
    public function isOutstanding(): bool
    {
        return $this === self::Unpaid || $this === self::PartiallyPaid;
    }

    /**
     * Reads an order's two money columns and says where it stands.
     *
     * Compared with `bccomp` rather than `<`/`==`: these arrive as decimal strings precisely so
     * they are never floats, and casting them to compare would undo that in the one place it
     * matters most.
     */
    public static function for(Order $order): self
    {
        return self::between(
            (string) $order->paid_amount,
            (string) $order->grand_total,
            (string) $order->written_off_amount,
            (string) $order->carrier_settled_amount,
        );
    }

    /**
     * **What closes a debt is cash, plus what was forgiven, plus what the carrier collected at
     * the door**, so all three are weighed against the total — an order is no longer owed once
     * they together reach it. They stay three numbers rather than one because the differences
     * between them are the whole point: see {@see WrittenOff} and
     * {@see OrderPaymentType::CarrierSettled}.
     *
     * **Only the forgiven one changes which state this answers.** An order closed partly by a
     * carrier settlement reads «مدفوعة بالكامل», and that is correct rather than a shortcut: the
     * customer paid every dinar they were billed, and part of it went to the courier by
     * arrangement. A write-off is the opposite fact — money that never arrived — which is why it,
     * and not this, gets a state of its own.
     *
     * The comparison against the total comes *before* the one against zero, so an order that
     * costs nothing — an office pickup with no lines yet — reads «مدفوعة بالكامل» rather than
     * «غير مدفوعة». Nothing is owed on it, and «غير مدفوعة» would put it in the list of orders
     * somebody is meant to chase.
     *
     * **This rule is written twice**, here and in {@see PaymentStatusExpression} for the list and
     * the counts. `OrderPaymentStatusFilterTest` asserts the SQL against this enum, so the pair
     * cannot drift.
     */
    public static function between(
        string $paid,
        string $grandTotal,
        string $writtenOff = '0',
        string $carrierSettled = '0',
    ): self {
        $covered = bcadd(bcadd($paid, $writtenOff, Money::SCALE), $carrierSettled, Money::SCALE);
        $forgiven = bccomp($writtenOff, '0', Money::SCALE) > 0;

        return match (true) {
            bccomp($covered, $grandTotal, Money::SCALE) > 0 => self::Overpaid,
            bccomp($covered, $grandTotal, Money::SCALE) === 0 => $forgiven ? self::WrittenOff : self::Paid,
            bccomp($covered, '0', Money::SCALE) <= 0 => self::Unpaid,
            default => self::PartiallyPaid,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
