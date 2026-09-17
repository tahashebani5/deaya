<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Inventory\Actions\ApplyStockChange;
use App\Support\Exceptions\DomainException;

/**
 * The cost layers for a (warehouse, size) fall short of what {@see InsufficientStock} already
 * cleared against `warehouse_stocks.quantity`.
 *
 * This should never fire in practice — `SUM(stock_batches.quantity_remaining)` is meant to equal
 * `warehouse_stocks.quantity` at all times, and {@see ApplyStockChange}
 * checks the balance before either is touched. It exists as the loud failure for the day that
 * invariant is somehow violated, rather than letting FIFO consumption silently stop short and
 * leave a movement half-costed.
 */
final class InsufficientBatchStock extends DomainException
{
    public static function make(int $warehouseId, int $stockItemId, string $available, string $requested): self
    {
        return new self(
            "دفعات التكلفة للمخزن رقم {$warehouseId} والمادة رقم {$stockItemId} ".
            "({$available}) لا تكفي للكمية المطلوبة ({$requested}) — الرصيد والدفعات غير متطابقين"
        );
    }
}
