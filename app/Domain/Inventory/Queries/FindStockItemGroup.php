<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\StockItemGroup;

/**
 * One material, by id.
 *
 * Exists so {@see InventoryService} can answer "which material is this?" without the Service
 * itself holding a query — the Service is the door, not a place for logic.
 *
 * `findOrFail`, so a product naming a material that does not exist is a 404 rendered by the
 * handler in bootstrap/app.php, exactly as it would be for a route-model binding.
 */
final class FindStockItemGroup
{
    public function __invoke(int $groupId): StockItemGroup
    {
        return StockItemGroup::query()->findOrFail($groupId);
    }
}
