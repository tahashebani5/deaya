<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * An image was addressed through a product that does not own it.
 *
 * Over HTTP the scoped route binding rejects this first with a 404, so this is the guard for
 * every other caller — a console command, an import, a future admin panel.
 */
final class ImageDoesNotBelongToProduct extends DomainException
{
    public static function make(int $imageId, int $productId): self
    {
        return new self("الصورة رقم {$imageId} لا تنتمي للمنتج رقم {$productId}");
    }
}
