<?php

declare(strict_types=1);

namespace App\Domain\Investor\Enums;

/**
 * Where a deal is in its life.
 *
 * Four states and a deliberately narrow map. **`Cancelled` is reachable from `Draft` alone** —
 * an open deal owns cost layers that FIFO will keep drawing from whatever its status says, so a
 * cancelled-but-funded deal would be a row no screen lists while its stock quietly goes on being
 * sold. A deal with goods is *closed*, not cancelled, and closing has real conditions attached.
 *
 * `Closed` is not reachable from the status endpoint at all — the same discipline
 * `PurchaseOrderStatus` applies to `Completed`. It is the outcome of `CloseDeal`, which checks
 * that nothing is left on the shelf and no money is left owing.
 */
enum DealStatus: string
{
    /** Being written. Nothing has arrived, and everything is still editable. */
    case Draft = 'draft';

    /** Live: goods may arrive against it, and its stock earns and loses. */
    case Open = 'open';

    /** Finished — stock gone, capital and profit returned to their wallets. */
    case Closed = 'closed';

    /** Abandoned before anything arrived. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Open => 'مفتوحة',
            self::Closed => 'مغلقة',
            self::Cancelled => 'ملغاة',
        };
    }

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Open, self::Cancelled],
            // Closing is its own endpoint because it has conditions; cancelling an open deal is
            // refused outright rather than offered and then refused.
            self::Open => [],
            self::Closed, self::Cancelled => [],
        };
    }

    /** Whether the terms may still be rewritten — the shape, the investors, the percentages. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Whether goods may be claimed for it and stock may carry it. */
    public function acceptsStock(): bool
    {
        return $this === self::Open;
    }

    public function isFinished(): bool
    {
        return $this === self::Closed || $this === self::Cancelled;
    }
}
