<?php

declare(strict_types=1);

namespace App\Domain\Vendor\DTOs;

use App\Domain\Inventory\DTOs\StockMovementData;

final readonly class StockArrivalItemData
{
    public function __construct(
        public int $stockItemId,
        /** Always positive, normalised to three decimal places — see {@see quantity()}. */
        public string $quantity,
        /**
         * What this line cost, carried over from the purchase order it fulfils. Always null
         * through {@see fromArray()} — the generic `POST /stock-arrivals` endpoint never accepts
         * cost — only `PurchaseOrder\Actions\ReceivePurchaseOrder` sets these, by constructing
         * this DTO directly, the same treatment {@see StockArrivalData::$purchaseOrderId} gets.
         */
        public ?string $unitCost = null,
        public ?string $totalCost = null,
        /**
         * Which deal financed this line, resolved by Investment from a claim made before the
         * goods left the supplier. Always null through {@see fromArray()}: the receiving clerk
         * has no such field on the screen and never chooses one — «الموظف لا يختار الصفقة أبداً»
         * — so only `ReceivePurchaseOrder` ever fills it, by building this DTO directly.
         */
        public ?int $investorDealId = null,
        /**
         * سعر السادة that deal sells this line to the press at, read from the deal beside the id
         * above and never typed here either. Null when the company financed the line, and null
         * on a deal opened without the term.
         */
        public ?string $printingSalePrice = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            stockItemId: (int) $validated['stock_item_id'],
            quantity: self::quantity($validated['quantity']),
        );
    }

    /**
     * Cast through string, never left as a float — the same reasoning
     * {@see StockMovementData::quantity()} carries: this number is
     * added to a balance, and binary drift on the way in is a discrepancy nobody could explain.
     */
    private static function quantity(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }
}
