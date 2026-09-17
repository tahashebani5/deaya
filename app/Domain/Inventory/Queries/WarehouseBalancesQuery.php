<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

use App\Domain\Inventory\Actions\ApplyStockChange;
use App\Domain\Inventory\Models\WarehouseStock;

/**
 * What one warehouse holds of a named set of sizes, as a map keyed by size.
 *
 * For callers that need to look before they leap — an order weighing all of its lines against a
 * warehouse in one go, so it can name every size that is short instead of failing on the first.
 * One query for the whole set, never one per size.
 *
 * **This answers "how much is there", never "may this be taken".** The check that decides a
 * movement is in {@see ApplyStockChange}, under a row lock; a balance read here is already
 * stale by the time the caller looks at it, and a caller that treated it as permission would be
 * the textbook lost update. What it is for is the *message*.
 */
final class WarehouseBalancesQuery
{
    /**
     * A size with no balance line in this warehouse is absent from the map rather than zero —
     * "never been here" and "here and empty" are the same answer to the caller, and the caller
     * is the one that decides what to call it.
     *
     * @param  list<int>  $stockItemIds
     * @return array<int, string>
     */
    public function __invoke(int $warehouseId, array $stockItemIds): array
    {
        if ($stockItemIds === []) {
            return [];
        }

        $rows = WarehouseStock::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('stock_item_id', $stockItemIds)
            ->get(['stock_item_id', 'quantity']);

        $balances = [];

        foreach ($rows as $row) {
            $balances[(int) $row->stock_item_id] = (string) $row->quantity;
        }

        return $balances;
    }
}
