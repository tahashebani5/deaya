<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Enums;

use App\Domain\Order\Enums\OrderStatus;

/**
 * The codes Nawris sends that mean something to us, and what each one means.
 *
 * **Read from their own status table, not inferred.** The first version of this map was compiled
 * from another codebase's observed behaviour, and three of its nine readings were wrong once the
 * carrier's published table was in hand — codes 3, 4 and 5, which is to say the whole ordinary
 * delivery path. Their table is quoted against each case below; a case with no quote is a case
 * nobody has checked.
 *
 * **This enum is the blast radius.** `OrderStatus::permission()` is enforced in a FormRequest, so
 * it guards the HTTP route and not the action — and the webhook job calls `ChangeOrderStatus`
 * directly, with no permission check on that path at all. That is the right architecture (the
 * domain does not know about permissions) but it means the map below is the only thing deciding
 * what an unauthenticated POST can do to an order. **Adding a case here is a security change, not
 * configuration.**
 *
 * **Anything not listed changes no state.** An unmapped code updates the stored raw label and is
 * logged, which is the only safe default: a carrier that invents a code must never have it
 * guessed at. Fourteen of their twenty-three are deliberately unmapped.
 */
enum NawrisStatusCode: int
{
    /**
     * «في الشركة» — sitting in their warehouse.
     *
     * **Not the road, so not «جاري التوصيل».** This was the first map's worst forward move: it
     * stamped `dispatched_at` and told the screen a courier was carrying a parcel still on their
     * shelf. Only code 4 puts an order out for delivery.
     */
    case AtTheirCompany = 3;

    /**
     * «مع المندوب» — a courier is holding it.
     *
     * **The state a parcel spends most of its life in, and the one the first map inverted**: it
     * read this as ambiguous and, whenever a `return_reason` happened to ride along, turned an
     * ordinary delivery notice into «راجع لدى المندوب». A false return on a parcel on its way to
     * the customer, and one our chain then makes you walk back by hand.
     */
    case WithTheCourier = 4;

    /**
     * «مرتجع مع الشركة» — the return is held by the carrier.
     *
     * Their company, not their courier: the link *after* «راجع لدى المندوب» in our chain. Sent
     * while the order is still on the road it is parked rather than forced, which is the chain
     * doing its job.
     */
    case ReturnWithCompany = 5;

    /** «مرتجع تم استلامه» — the return reached their branch and came back to us. */
    case ReturnReceived = 6;

    /** «تم التسليم» — delivered, and the money collected. The only code that writes to the ledger. */
    case Delivered = 7;

    /**
     * «تم التسوية» — their books say the account between us is closed.
     *
     * **Named so it is not mistaken for unknown, and mapped to nothing on purpose.** Our «تم
     * التسوية» is our own decision about our own ledger; theirs is a statement about theirs, and
     * the two settle on different days over different figures. Letting this code close our order
     * would hand the carrier the last word on when we consider ourselves paid.
     *
     * Recorded like every other code — their label lands on the parcel — and nothing moves.
     */
    case TheirSettlement = 8;

    /** «مرتجع معاد إرساله» — a return went out again. */
    case ReturnSentAgain = 10;

    /** «مرتجع معدوم» — the return was written off. Terminal. */
    case ReturnWrittenOff = 12;

    /** «مرتجع مع المندوب» — on its way back, still with the courier. */
    case ComingBack = 15;

    case OnTheWayToBranch = 16;
    /** «مرتجع في الفرع» — sitting at the carrier's branch. */
    case ReturnAtBranch = 19;

    /**
     * Which of our statuses this code asks for, or null when it asks for nothing.
     *
     * **Asking for a status is not the same as getting it.** `ApplyNawrisStatus` still checks
     * `OrderStatus::canMoveTo()` from wherever the order actually is, and parks the event when the
     * move is not legal — our return chain is walked one link at a time on purpose, and Nawris
     * does not walk it. See NAWRIS-INTEGRATION.md §3.2.
     */
    public function target(): ?OrderStatus
    {
        return match ($this) {
            // Their warehouse says nothing about our order beyond "they have it", and their
            // settlement says nothing about ours.
             self::TheirSettlement => null,

            self::WithTheCourier,self::AtTheirCompany,self::OnTheWayToBranch => OrderStatus::OutForDelivery,
            self::ReturnWithCompany, self::ReturnAtBranch => OrderStatus::ReturnedCarrier,
            self::ComingBack => OrderStatus::ReturnedCourier,
            self::ReturnReceived => OrderStatus::ReturnedOffice,
            self::Delivered => OrderStatus::Delivered,
            self::ReturnSentAgain => OrderStatus::Resend,
            self::ReturnWrittenOff => OrderStatus::Cancelled,
        };
    }

    /**
     * Whether this code ends the parcel's journey.
     *
     * Stamps `closed_at`, which is what makes "still out there" a query rather than a list of
     * statuses somebody has to keep in step.
     */
    public function closesTheParcel(): bool
    {
        return $this === self::Delivered
            || $this === self::ReturnReceived
            || $this === self::ReturnWrittenOff;
    }

    /** The one code that moves money. */
    public function collectsMoney(): bool
    {
        return $this === self::Delivered;
    }

    public static function tryFromCode(mixed $code): ?self
    {
        return is_numeric($code) ? self::tryFrom((int) $code) : null;
    }
}
