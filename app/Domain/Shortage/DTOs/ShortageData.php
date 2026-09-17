<?php

declare(strict_types=1);

namespace App\Domain\Shortage\DTOs;

use App\Domain\Catalog\Enums\PricingUnit;

/**
 * A shortage as a person typed it.
 *
 * **Manual entry only.** An order-born shortage is never built from one of these: every field
 * here is copied off the line by `SyncShortagesFromOrder`, which reads an `OrderLineShortage`
 * instead. Two DTOs rather than one with half its fields optional, because the two arrive from
 * genuinely different places and the day the manual form gains a field the sync must not silently
 * acquire it too.
 *
 * `status`, `code` and `source` are absent on purpose — the server decides all three, the shape
 * `DealExpenseData` uses for `is_landed`.
 */
final readonly class ShortageData
{
    public function __construct(
        public string $name,
        public PricingUnit $unit,
        public string $requiredQuantity,

        /**
         * The catalogue entry, when there is one.
         *
         * **Optional, and that is the decision rather than an oversight.** What gets written down
         * by hand is very often what the catalogue has never heard of — a roll of tape, somebody
         * else's brand of sack — and a required product link makes an employee pick the nearest
         * wrong row to get past the field, which puts a wrong answer in a column that reports
         * «كم مرة نقص هذا المنتج؟». See SHORTAGES-DESIGN §٢٫١.
         */
        public ?int $productId = null,
        public ?int $productVariantId = null,

        public ?int $assignedToUserId = null,
        public ?string $description = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            name: trim((string) $validated['name']),
            unit: PricingUnit::from((string) $validated['unit']),
            // A quantity, not money: three places, and a string from here on.
            requiredQuantity: bcadd((string) $validated['required_quantity'], '0', 3),
            productId: isset($validated['product_id']) ? (int) $validated['product_id'] : null,
            productVariantId: isset($validated['product_variant_id'])
                ? (int) $validated['product_variant_id']
                : null,
            assignedToUserId: isset($validated['assigned_to_user_id'])
                ? (int) $validated['assigned_to_user_id']
                : null,
            description: isset($validated['description']) && trim((string) $validated['description']) !== ''
                ? trim((string) $validated['description'])
                : null,
        );
    }
}
