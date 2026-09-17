<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\DTOs;

use App\Domain\Vendor\DTOs\StockArrivalItemData;

final readonly class PurchaseOrderItemData
{
    public function __construct(
        public int $stockItemId,
        /** Always positive, normalised to three decimal places — see {@see quantityOrdered()}. */
        public string $quantityOrdered,
        /**
         * What this line costs in total, before its share of the order's additional costs —
         * negotiated with the vendor, never derived. There is no catalogue price for what *we*
         * pay a vendor, so this always comes from the request, unlike `OrderItemData::unitPrice`,
         * which the catalogue overrides whenever one exists. The per-unit figure is computed
         * from this, server-side, by {@see AllocatePurchaseOrderAdditionalCosts} — never the
         * other way around.
         */
        public string $baseTotalCost,
        /** Present when updating an existing line; null when creating a new one. */
        public ?int $id = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  One entry from the request's `items` array.
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            stockItemId: (int) $validated['stock_item_id'],
            quantityOrdered: self::quantityOrdered($validated['quantity_ordered']),
            baseTotalCost: self::money($validated['base_total_cost']),
            id: isset($validated['id']) ? (int) $validated['id'] : null,
        );
    }

    /**
     * Cast through string, never left as a float — the same reasoning
     * {@see StockArrivalItemData} carries.
     */
    private static function quantityOrdered(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    /** Cast through string at two decimal places — money, never a float. */
    private static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
