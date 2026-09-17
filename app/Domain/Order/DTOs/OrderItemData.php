<?php

declare(strict_types=1);

namespace App\Domain\Order\DTOs;

use App\Domain\Order\Support\TransitionFields;

/**
 * One requested line, before it has been priced.
 *
 * **No warehouse quantity.** What comes off the shelf is asked for on the way into «جاهزة» by
 * the person holding the parcel — see {@see TransitionFields} — not
 * when the order is taken, where nobody has been near a scale and the parcel does not exist yet.
 *
 * `unitPrice` is the exception that keeps quote-on-request products orderable. The catalogue
 * carries products whose price «حسب الطلب» — the reinforced 3D paper bags — and refusing to
 * price them is exactly right for a quote endpoint but would make a whole category impossible
 * to sell. So a clerk may name a price for those, and **only** those: for a product with listed
 * prices this field is ignored and the catalogue wins, which is what stops a posted number
 * undercutting an agreed rate.
 */
final readonly class OrderItemData
{
    public function __construct(
        public int $productId,
        public int $productVariantId,
        public string $quantity,
        /** Honoured only when the product is priced on request. */
        public ?string $unitPrice = null,
        public ?string $notes = null,
        public int $sortOrder = 0,

        /**
         * Whether a missing price on a quote-on-request product is allowed to stand.
         *
         * **True only for a request from the customer app**, where it is the normal case: the
         * app is never told a price for such a product, so it cannot send one, and the shop
         * quotes the line on the move that accepts the request.
         *
         * False everywhere else, which keeps the old rule intact for staff: a clerk writing an
         * order at the counter *is* the person naming the price, and an empty box there is a
         * mistake rather than a line awaiting a quote. Defaulting to false is what makes that
         * true of every existing caller without touching one of them.
         */
        public bool $allowsQuoteLater = false,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(
        array $validated,
        int $index = 0,
        bool $allowsQuoteLater = false,
    ): self {
        $price = $validated['unit_price'] ?? null;

        return new self(
            productId: (int) $validated['product_id'],
            productVariantId: (int) $validated['product_variant_id'],
            quantity: (string) $validated['quantity'],
            unitPrice: $price !== null && $price !== '' ? (string) $price : null,
            notes: isset($validated['notes']) && $validated['notes'] !== ''
                ? (string) $validated['notes']
                : null,
            sortOrder: (int) ($validated['sort_order'] ?? $index),
            allowsQuoteLater: $allowsQuoteLater,
        );
    }
}
