<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\DTOs;

use Carbon\CarbonImmutable;

final readonly class PurchaseOrderData
{
    /**
     * @param  list<PurchaseOrderItemData>  $items
     * @param  list<PurchaseOrderAdditionalCostData>  $additionalCosts
     */
    public function __construct(
        public int $vendorId,
        public ?int $warehouseId,
        public CarbonImmutable $orderDate,
        public array $items,
        public array $additionalCosts = [],
        public ?CarbonImmutable $expectedDate = null,
        public ?string $notes = null,
    ) {}

    /**
     * Built from already-validated request data — the one place an array crosses into the
     * domain.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            vendorId: (int) $validated['vendor_id'],
            warehouseId: self::intOrNull($validated['warehouse_id'] ?? null),
            orderDate: CarbonImmutable::parse($validated['order_date']),
            items: array_map(PurchaseOrderItemData::fromArray(...), $validated['items']),
            // Absent or empty means "no additional costs" — the same "send the whole current
            // set every time" contract `items` already carries, not a partial update.
            additionalCosts: array_map(PurchaseOrderAdditionalCostData::fromArray(...), $validated['additional_costs'] ?? []),
            expectedDate: isset($validated['expected_date']) && $validated['expected_date'] !== ''
                ? CarbonImmutable::parse($validated['expected_date'])
                : null,
            notes: self::textOrNull($validated['notes'] ?? null),
        );
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    private static function textOrNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }
}
