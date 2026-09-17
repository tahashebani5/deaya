<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * The product's last photo cannot be removed.
 *
 * Every product carries at least one image, and that is a standing invariant rather than a rule
 * that only holds while one is being created — see PRODUCT-IMAGE-REQUIRED-DESIGN.md. Enforcing
 * it only at creation would leave the road back to an imageless product open and one tap long.
 *
 * The message names the way out, because there is one and it is not obvious: upload the
 * replacement first, then remove this one. At no point does the product have nothing.
 */
final class ProductMustKeepOneImage extends DomainException
{
    public static function make(): self
    {
        return new self('لا يمكن حذف الصورة الوحيدة للمنتج — ارفع الصورة البديلة أولاً ثم احذف هذه');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        // Reported under `image` so a client can surface it beside the photo it refused to
        // remove, rather than as a bare banner with nothing to point at.
        return ['image' => [$this->getMessage()]];
    }
}
