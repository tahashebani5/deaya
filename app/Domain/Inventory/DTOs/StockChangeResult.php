<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

use App\Domain\Inventory\Actions\ApplyStockChange;
use App\Domain\Inventory\Models\WarehouseStock;

/**
 * What {@see ApplyStockChange::decrease()} actually did: the
 * balance it left behind, and every batch it drew from to get there.
 *
 * `$consumed` is empty for nothing today — a decrease always draws from at least one batch, since
 * the balance and the batches are kept in lockstep — but the type stays a plain list rather than
 * a guaranteed-non-empty one so a future caller is not tempted to assume its shape.
 */
final readonly class StockChangeResult
{
    /**
     * @param  list<BatchDraw>  $consumed
     */
    public function __construct(
        public WarehouseStock $stock,
        public array $consumed = [],
    ) {}
}
