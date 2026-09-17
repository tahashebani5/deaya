<?php

declare(strict_types=1);

namespace App\Domain\Vendor\DTOs;

use App\Domain\Vendor\Actions\RecordStockArrival;

final readonly class StockArrivalData
{
    /**
     * @param  list<StockArrivalItemData>  $items
     */
    public function __construct(
        public int $vendorId,
        public int $warehouseId,
        /** Stamped from the authenticated user, never read from the payload. */
        public int $receivedBy,
        public array $items,
        public ?string $invoiceNumber = null,
        public ?string $notes = null,
        /**
         * The purchase order this shipment fulfils, if any. Always null through
         * {@see fromArray()} — the generic `POST /stock-arrivals` endpoint never accepts this
         * field. Only `PurchaseOrder\Actions\ReceivePurchaseOrder` sets it, by constructing this
         * DTO directly, after which {@see RecordStockArrival} does
         * nothing with it beyond writing the column — every effect it has on the order itself
         * is applied by that action, one layer up, so Vendor stays ignorant of PurchaseOrder's
         * rules.
         */
        public ?int $purchaseOrderId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated, int $receivedBy): self
    {
        return new self(
            vendorId: (int) $validated['vendor_id'],
            warehouseId: (int) $validated['warehouse_id'],
            receivedBy: $receivedBy,
            items: array_map(StockArrivalItemData::fromArray(...), $validated['items']),
            invoiceNumber: self::textOrNull($validated['invoice_number'] ?? null),
            notes: self::textOrNull($validated['notes'] ?? null),
        );
    }

    private static function textOrNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }
}
