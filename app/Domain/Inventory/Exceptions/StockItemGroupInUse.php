<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A material something still points at was about to be deleted.
 *
 * Both foreign keys are `nullOnDelete`, so this would not orphan a row — it would do something
 * quieter and worse. A product would keep its sizes and lose the rule that files them: the next
 * save would resolve nothing, every variant would silently detach from its shelf, and the failure
 * would surface weeks later as an order that cannot be fulfilled.
 *
 * The fix is a real edit — move the sizes to another material, or point the products elsewhere —
 * not a flag.
 */
final class StockItemGroupInUse extends DomainException
{
    public static function make(string $name, int $items, int $products): self
    {
        $parts = [];

        if ($items > 0) {
            $parts[] = "{$items} مادةً";
        }

        if ($products > 0) {
            $parts[] = "{$products} منتجاً";
        }

        return new self("لا يمكن حذف «{$name}» لأن ".implode(' و', $parts).' مرتبط بها');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['stock_item_group' => [$this->getMessage()]];
    }
}
