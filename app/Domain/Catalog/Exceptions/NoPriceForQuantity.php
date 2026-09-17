<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * The variant has no tier covering this quantity — its price list has a hole below the
 * requested amount, which is a catalogue data problem rather than a customer mistake.
 */
final class NoPriceForQuantity extends DomainException
{
    public static function make(string $variantLabel, string $quantity): self
    {
        return new self("لا يوجد سعر مُعرَّف للمقاس «{$variantLabel}» عند الكمية {$quantity}");
    }
}
