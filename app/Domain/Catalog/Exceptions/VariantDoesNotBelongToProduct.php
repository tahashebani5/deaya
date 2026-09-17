<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A variant id was quoted against a product that does not own it.
 */
final class VariantDoesNotBelongToProduct extends DomainException
{
    public static function make(int $variantId, int $productId): self
    {
        return new self("المقاس رقم {$variantId} لا ينتمي للمنتج رقم {$productId}");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['variant_id' => [$this->getMessage()]];
    }
}
