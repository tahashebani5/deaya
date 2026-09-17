<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Two variants of one product would end up with the same label.
 *
 * The database refuses it via a unique index; catching the case here turns what would be a 500
 * with a raw SQL message into a 422 that says which size is at fault.
 */
final class DuplicateVariantLabel extends DomainException
{
    public static function make(string $label): self
    {
        return new self("المقاس «{$label}» مُعرَّف أكثر من مرة لنفس المنتج");
    }
}
