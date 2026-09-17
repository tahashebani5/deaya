<?php

declare(strict_types=1);

namespace App\Domain\Order\Enums;

use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderPayment;

/**
 * What a row in the payment ledger *is*.
 *
 * **Four, and each one is a different sentence about the same amount.** Money coming in is one
 * thing; money going back out is two different things wearing the same sign. A clerk who typed
 * 500 instead of 50 did not refund 450 — nothing left the drawer, and the entry describes an
 * event that never happened. Recording both as one type would make «كم رددنا للعملاء هذا
 * الشهر؟» unanswerable, because the answer would be inflated by every typo anyone ever
 * corrected.
 *
 * **The fourth says money is not coming at all.** A write-off closes a debt without any cash
 * moving in either direction — see {@see WriteOff} — and it is a separate type for exactly the
 * reason the other three are separate: recording forgiven money as a payment would put five
 * dinars in the drawer report that nobody ever put in the drawer.
 *
 * {@see amount} is always positive on every one of the four. The direction lives here, exactly
 * as `MovementType` holds it for the stock ledger rather than letting a quantity go negative:
 * a signed column invites a sum that is right by accident and a filter that is wrong on purpose.
 *
 * @see OrderPayment
 */
enum OrderPaymentType: string
{
    /** The customer paid. The only type that increases what has been paid. */
    case Payment = 'payment';

    /** The entry was a mistake and describes nothing that happened. Points at the row it undoes. */
    case Reversal = 'reversal';

    /** Money genuinely went back to the customer. A cash event, unlike a reversal. */
    case Refund = 'refund';

    /**
     * The business decided the rest is not coming, and closed the debt itself.
     *
     * **Not a payment, and not a discount.** No cash moved, so it is nothing like the first
     * three; and the invoice keeps saying what it always said, so it is nothing like a discount
     * either. The customer was billed 110, handed over 105, and the five that never arrived is
     * recorded as five that never arrived — which is what makes it findable later as a loss
     * rather than lost inside a total.
     *
     * It moves {@see Order::$written_off_amount} and never `paid_amount`, so «كم قبضنا؟» keeps
     * meaning cash.
     */
    case WriteOff = 'write_off';

    /**
     * The customer paid this part to the courier, not to us.
     *
     * **The fifth, and the second that moves no cash of ours — but for the opposite reason to a
     * write-off.** A write-off says the money is not coming. This says it came, in full, and went
     * somewhere else by arrangement: we subtract our `delivery_price` from the COD before a
     * parcel is handed to Nawris, so the carrier collects that amount at the door on their own
     * account. See NAWRIS-INTEGRATION.md §5.2.
     *
     * Recording it as a {@see Payment} would put money in the drawer report that never reached
     * the drawer. Recording it as a {@see WriteOff} would post a loss for every delivery, when a
     * completed sale is the one thing that did happen. So it moves
     * {@see Order::$carrier_settled_amount}, a third total, and «كم قبضنا؟» keeps meaning cash.
     */
    case CarrierSettled = 'carrier_settled';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'دفعة',
            self::Reversal => 'إلغاء قيد',
            self::Refund => 'ردّ مبلغ',
            self::WriteOff => 'شطب فرق',
            self::CarrierSettled => 'سُدِّدت لدى الناقل',
        };
    }

    /** Whether this entry adds to what the order has been paid. */
    public function isIncoming(): bool
    {
        return $this === self::Payment;
    }

    /**
     * Whether this entry adds to what has been forgiven rather than to what has been paid.
     *
     * Only the write-off itself answers here. **A reversal of one answers for the row it
     * undoes**, which the row alone cannot know — see {@see OrderPayment::affectsWriteOff()},
     * which is what callers actually ask.
     */
    public function isWriteOff(): bool
    {
        return $this === self::WriteOff;
    }

    /**
     * Whether this entry adds to what the carrier collected on its own account.
     *
     * The third total, and the same shape of question {@see isWriteOff()} answers for the second.
     * **A reversal of one answers for the row it undoes** — see
     * {@see OrderPayment::affectsCarrierSettlement()}, which is what callers actually ask.
     */
    public function isCarrierSettled(): bool
    {
        return $this === self::CarrierSettled;
    }

    /**
     * Whether this entry increases the total it belongs to, rather than reducing it.
     *
     * A payment adds to what was paid, a write-off to what was forgiven, and a carrier settlement
     * to what the courier took at the door; a refund and a reversal take back off whichever of
     * the three their row belongs to. Which total that is, is a separate question — see
     * {@see isWriteOff()} and {@see isCarrierSettled()}.
     */
    public function isCredit(): bool
    {
        return $this === self::Payment
            || $this === self::WriteOff
            || $this === self::CarrierSettled;
    }

    /**
     * Whether this entry moved real money **into our hands**.
     *
     * Three of the five did not: a reversal describes an event that never happened, a write-off
     * an amount that was never collected, and a carrier settlement an amount collected by
     * somebody else. That last one is why this cannot be read as "is it a credit" — a carrier
     * settlement is a credit and is emphatically not cash, and a drawer report that counted it
     * would claim the delivery fee of every parcel.
     */
    public function movedCash(): bool
    {
        return $this === self::Payment || $this === self::Refund;
    }

    /**
     * Whether an entry of this type names the method money moved by.
     *
     * A payment and a refund do; a reversal, a write-off and a carrier settlement cannot, because
     * no money of ours moved for them to have one. The `order_payments_shape` CHECK states the
     * same rule in SQL, so no path can write a row this would call malformed.
     */
    public function namesAMethod(): bool
    {
        return $this->movedCash();
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
