<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Support\Exceptions\DomainException;

/**
 * A fraction of a countable thing was about to be moved.
 *
 * `quantity` is decimal because a per-kilo product's stock is a weight — but the same column
 * holds shipping bags, and 2.5 of those is not a quantity, it is a typo. The rule already exists
 * in the catalogue as {@see PricingUnit::requiresWholeQuantities()}, which is what stops an order
 * for half a bag; the ledger asks Catalog the same question rather than inventing a second
 * answer that could drift from it.
 */
final class FractionalQuantityNotAllowed extends DomainException
{
    public static function make(string $quantity): self
    {
        return new self("الكمية ({$quantity}) يجب أن تكون عدداً صحيحاً لهذا المنتج — لا يُباع بالوزن");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['quantity' => [$this->getMessage()]];
    }
}
