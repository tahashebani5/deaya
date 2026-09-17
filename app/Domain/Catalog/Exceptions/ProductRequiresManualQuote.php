<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * The product is quoted by hand, so no price can be computed for it.
 *
 * Refusing is the whole point: the reinforced 3D paper bags vary by size and specification, and
 * returning any number here would be a made-up commitment to the customer.
 */
final class ProductRequiresManualQuote extends DomainException
{
    public static function make(string $productName): self
    {
        return new self("سعر «{$productName}» يُحدَّد حسب الطلب — يرجى إرسال المقاس والكمية للحصول على عرض سعر");
    }
}
