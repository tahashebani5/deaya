<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\StockItem;

/**
 * One shelf, by id.
 *
 * Exists so {@see InventoryService} can answer "which item is this?" without the Service itself
 * holding a query — the Service is the door, not a place for logic.
 *
 * `findOrFail`, so a movement naming an item that does not exist is a 404 rendered by the handler
 * in bootstrap/app.php, exactly as it would be for a route-model binding. There is nothing for a
 * caller to check and nothing to forget.
 */
final class FindStockItem
{
    public function __invoke(int $stockItemId): StockItem
    {
        return StockItem::query()->findOrFail($stockItemId);
    }
}
