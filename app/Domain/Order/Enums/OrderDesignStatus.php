<?php

declare(strict_types=1);

namespace App\Domain\Order\Enums;

/**
 * Where one *version* of an order's artwork stands with the customer.
 *
 * This is the enum that keeps «لم يعجبه التصميم، حط التالي» out of {@see OrderStatus}. A
 * rejected design and a new proposal are not new states of the order — the order is still
 * «قيد التصميم» throughout — they are rows in a list, each with the reason it was turned down.
 * Modelling it the other way would have meant three more order statuses to express one
 * conversation, and no way at all to answer "how many versions did this take?".
 */
enum OrderDesignStatus: string
{
    /** Sent to the customer, waiting on them. */
    case Proposed = 'proposed';

    /** Signed off. Printing is unblocked by exactly this. */
    case Approved = 'approved';

    /** Turned down, with `rejection_reason` saying why. */
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'مقترح',
            self::Approved => 'معتمد',
            self::Rejected => 'مرفوض',
        };
    }

    /** Decided one way or the other — no longer waiting on the customer. */
    public function isReviewed(): bool
    {
        return $this !== self::Proposed;
    }

    /**
     * Whether a rejection reason must accompany this outcome.
     *
     * The whole value of tracking versions is knowing *why* one was replaced; a rejection with
     * no reason turns the history into a count.
     */
    public function requiresReason(): bool
    {
        return $this === self::Rejected;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
