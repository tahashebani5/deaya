<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\DTOs;

use App\Domain\Vendor\DTOs\ReverseStockArrivalData;

/**
 * Undoing a receipt posted against a purchase order.
 *
 * Thin on purpose: this module's part of the act is rolling its own paperwork back, and the
 * three things a reversal actually needs belong to the document — see
 * {@see ReverseStockArrivalData}, which this becomes on the way through.
 */
final readonly class ReversePurchaseOrderReceiptData
{
    public function __construct(
        /** Why the receipt was wrong. Required by the request that builds this. */
        public string $reason,
        /** Stamped from the authenticated user, never read from the payload. */
        public int $reversedBy,
        /** Read off the caller's own grants at the boundary, never sent by a client. */
        public bool $ignoreWindow = false,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated, int $reversedBy, bool $ignoreWindow): self
    {
        return new self(
            reason: trim((string) $validated['reason']),
            reversedBy: $reversedBy,
            ignoreWindow: $ignoreWindow,
        );
    }

    public function toArrivalData(): ReverseStockArrivalData
    {
        return new ReverseStockArrivalData(
            reason: $this->reason,
            reversedBy: $this->reversedBy,
            ignoreWindow: $this->ignoreWindow,
        );
    }
}
