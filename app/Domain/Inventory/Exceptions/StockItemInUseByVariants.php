<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Inventory\Models\StockItem;
use App\Support\Exceptions\DomainException;

/**
 * A stock item some product size still draws from was about to be deleted.
 *
 * Deleting is soft and the foreign key is `nullOnDelete`, so this would not orphan a row — it
 * would do something quieter and worse: the variant keeps working right up until somebody tries
 * to fulfil an order with it, and then fails with «هذا المقاس غير مرتبط بمادة» about a link
 * that was silently cut weeks earlier by a delete nobody connected to it.
 *
 * Counts soft-deleted variants too — see {@see StockItem::isUsedByAnyVariant()}.
 */
final class StockItemInUseByVariants extends DomainException
{
    public static function make(string $name, int $count): self
    {
        return new self("لا يمكن حذف «{$name}» لأن {$count} مقاساً يسحب منه — غيّر ارتباط المقاسات أولاً");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['stock_item' => [$this->getMessage()]];
    }
}
