<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A warehouse with stock on its shelves was about to be deleted.
 *
 * Allowing it would leave a balance inside a place the API says does not exist: the stock would
 * vanish from every listing while the ledger still insists it arrived and never left, and the
 * first person to notice would be whoever is trying to make the numbers add up months later.
 *
 * The fix is a real operation, not a flag: move the stock somewhere else, or write it off with
 * an adjustment. Both leave a record of where it went, which is exactly what deleting the
 * warehouse would have destroyed.
 */
final class WarehouseStillHoldsStock extends DomainException
{
    public static function make(): self
    {
        return new self('لا يمكن حذف مخزن يحتوي على كمية — انقل الكمية إلى مخزن آخر أو سوِّ الجرد أولاً');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['warehouse' => [$this->getMessage()]];
    }
}
