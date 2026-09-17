<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Enums;

/**
 * Where a purchase order is, and the only moves it may make from there.
 *
 * **This enum is the state machine**, the same role {@see OrderStatus} plays for orders, sized
 * down to what a purchase order actually needs. The map in {@see allowedNext()} is the single
 * definition of what is legal; both the manual status endpoint and
 * {@see ReceivePurchaseOrder} answer to it.
 *
 * **`Arrived` means "sent to the vendor, nothing has shown up yet"**, and an order reaches it
 * only by a deliberate "mark as sent" (`PATCH .../status`). There is no partially-received state
 * to sit in: the first shipment takes the order straight to `Completed` however much of it turned
 * up, so "in motion" and "on the shelf" are the only two things this enum has to tell apart.
 *
 * `Completed` is reachable only from stock actually arriving, never as a manual target — see
 * `ChangePurchaseOrderStatusRequest`. **A purchase order is done when a delivery posts against
 * it, whether or not the delivery was short** — «تسجيل شحنات دوما يخليها مكتملة حتى لو في نواقص
 * وتسجل كنواقص», the owner on 2026-09-06. What the supplier still owes is reported off the lines
 * (`quantity_remaining`), not held in the status; see {@see ReceivePurchaseOrder}.
 */
enum PurchaseOrderStatus: string
{
    /** Just created. The only status a purchase order may still be edited in. */
    case New = 'new';

    /** In motion — sent, awaiting the vendor. See the class docblock. */
    case Arrived = 'arrived';

    /**
     * A delivery has been posted against it — short, exact or over. The end of the road.
     *
     * **With one exit, and it is not a transition.** A receipt entered in error is undone by
     * `ReversePurchaseOrderReceipt`, which puts the order back to {@see Arrived} — but only by
     * taking the stock off the shelf again first, and only while `ReverseStockArrival` allows
     * it. That is why `allowedNext()` below still says nothing follows `completed`: undoing a
     * receipt is a correction to the ledger that happens to reopen the paperwork, never a status
     * somebody may choose. `ChangePurchaseOrderStatusRequest` keeps refusing it.
     */
    case Completed = 'completed';

    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'جديد',
            self::Arrived => 'قيد الاستلام',
            self::Completed => 'مكتمل',
            self::Cancelled => 'ملغى',
        };
    }

    /**
     * Every move this status may make.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::New => [self::Arrived, self::Cancelled],
            self::Arrived => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /** Finished. Nothing follows, and no *transition* may reopen it — see {@see Completed}. */
    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    /** Whether the order's fields and lines may still be changed with `PUT`. */
    public function isEditable(): bool
    {
        return $this === self::New;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
