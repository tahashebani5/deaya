<?php

declare(strict_types=1);

namespace App\Domain\Customer\Queries;

/**
 * The orders the customer list can be read in.
 *
 * Two, and they answer different questions. {@see self::Newest} is the register — who was
 * added last — and is what the list has always been. {@see self::LeastRecentOrder} turns it
 * into a call sheet: the customer nobody has heard from for longest at the top.
 */
enum CustomerSort: string
{
    /** Newest first, by id. The register. */
    case Newest = 'newest';

    /**
     * The longest since their last order, first — «الزبائن اللي ليهم فترة ماطلبوش».
     *
     * Customers who have never ordered come **last**, not first. They are the subject of a
     * filter of their own ({@see CustomerFilters::$hasOrders}), and a call sheet that opened on
     * fifty names nobody has ever sold to would bury the ones worth ringing.
     */
    case LeastRecentOrder = 'least_recent_order';

    /**
     * A word from a query string, or {@see self::Newest} when it is one this API does not know.
     *
     * Unknown is not refused: a list is worth answering with its default order, and the client
     * that sent the word gets back a page rather than a 422 it has no screen for.
     */
    public static function fromWire(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Newest;
    }

    /**
     * Whether reading the list this way means reading the orders — and therefore whether it is
     * a question only a holder of `orders.view` may ask.
     */
    public function readsOrders(): bool
    {
        return $this === self::LeastRecentOrder;
    }
}
