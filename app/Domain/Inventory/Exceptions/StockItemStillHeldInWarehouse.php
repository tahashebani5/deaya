<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A stock item with quantity still on a shelf somewhere was about to be deleted.
 *
 * The mirror of {@see WarehouseStillHoldsStock}, and refused for the same reason: the balance
 * would survive inside an item the API says does not exist, so the stock vanishes from every
 * listing while the ledger still insists it arrived and never left.
 *
 * The fix is a real operation — use it up, or write it off with an adjustment. Both leave a
 * record of where it went.
 */
final class StockItemStillHeldInWarehouse extends DomainException
{
    public static function make(string $name): self
    {
        return new self("لا يمكن حذف «{$name}» لأن هناك كمية منه في المخازن — سوِّ الجرد أو انقل الكمية أولاً");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['stock_item' => [$this->getMessage()]];
    }
}
