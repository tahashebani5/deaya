<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Someone tried to delete a category that products are still recorded under.
 *
 * Refusing is the whole point of the rule. Deleting is soft, so the row would survive and the
 * products would keep pointing at it — but the API would stop returning it, and every one of
 * those products would render a category nobody can name or restore. Deactivating does what the
 * person actually wants: it disappears from the picker and leaves the records intact.
 */
final class ProductCategoryInUse extends DomainException
{
    public static function make(string $name, int $productCount): self
    {
        return new self(
            "لا يمكن حذف «{$name}» لارتباطه بـ {$productCount} من المنتجات. أوقفه بدل حذفه ليختفي من قوائم الاختيار.",
        );
    }
}
