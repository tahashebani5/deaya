<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\DTOs;

final readonly class ReceivePurchaseOrderData
{
    /**
     * @param  list<ReceivePurchaseOrderItemData>  $items
     */
    public function __construct(
        /** Stamped from the authenticated user, never read from the payload. */
        public int $receivedBy,
        public array $items,
        public ?string $invoiceNumber = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated, int $receivedBy): self
    {
        return new self(
            receivedBy: $receivedBy,
            items: array_map(ReceivePurchaseOrderItemData::fromArray(...), $validated['items']),
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
