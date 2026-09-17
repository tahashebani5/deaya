<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

use App\Domain\Catalog\Enums\PricingUnit;

/**
 * What a given quantity of a given variant costs, and why.
 *
 * Every money and quantity value is a decimal **string**, not a float. 1.10 has no exact binary
 * representation, so the moment a price becomes a float it stops being the number the catalogue
 * printed — and a total built from it drifts further with each multiplication.
 *
 * `appliedTierMinQuantity` and `nextTier*` are carried so the app can explain the price rather
 * than just state it: "you are on the 300+ rate" and "order 47 more to reach the 1000+ rate".
 */
final readonly class PriceQuote
{
    public function __construct(
        public string $quantity,
        public PricingUnit $unit,
        public string $unitPrice,
        public string $total,
        public string $appliedTierMinQuantity,
        public ?string $nextTierMinQuantity = null,
        public ?string $nextTierUnitPrice = null,
        public ?string $quantityToNextTier = null,
    ) {}
}
