<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\DTOs;

final readonly class PurchaseOrderAdditionalCostData
{
    public function __construct(
        public string $name,
        /** Always non-negative, normalised to two decimal places — money, never a float. */
        public string $amount,
        /** Present when updating an existing cost; null when creating a new one. */
        public ?int $id = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  One entry from the request's `additional_costs` array.
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            name: trim((string) $validated['name']),
            amount: number_format((float) $validated['amount'], 2, '.', ''),
            id: isset($validated['id']) ? (int) $validated['id'] : null,
        );
    }
}
