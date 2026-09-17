<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

final readonly class PriceTierData
{
    public function __construct(
        /** Inclusive floor, as a decimal string. */
        public string $minQuantity,
        /** Price per unit at this tier, as a decimal string. */
        public string $unitPrice,
    ) {}

    /**
     * @param  array<string, mixed>  $tier
     */
    public static function fromArray(array $tier): self
    {
        return new self(
            minQuantity: (string) $tier['min_quantity'],
            unitPrice: (string) $tier['unit_price'],
        );
    }
}
