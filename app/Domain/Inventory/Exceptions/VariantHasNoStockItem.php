<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Stock was about to move for a size that points at no shelf.
 *
 * `product_variants.stock_item_id` is nullable on purpose — a quote-only product that is never
 * stocked has no shelf, and a NOT NULL column would only teach whoever creates it to point at
 * some unrelated row to get past the form. The cost of that choice is exactly this exception:
 * every path that actually moves stock has to say so, in Arabic, instead of dereferencing null
 * and becoming a 500 that names nothing.
 *
 * Guards both directions — an order being fulfilled from a size with no shelf, and a shipment
 * arriving against one.
 */
final class VariantHasNoStockItem extends DomainException
{
    public static function make(string $variantName): self
    {
        return new self("«{$variantName}» غير مرتبط بمادة — اربطه بمادة قبل حركة المخزون");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['stock_item_id' => [$this->getMessage()]];
    }
}
