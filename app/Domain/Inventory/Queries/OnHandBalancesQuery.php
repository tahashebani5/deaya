<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

use App\Domain\Inventory\Models\WarehouseStock;

/**
 * How much of each size the business holds **anywhere**.
 *
 * The cross-warehouse twin of {@see WarehouseBalancesQuery}, and it exists for one caller: a
 * screen that has to say what is on hand *before* a warehouse has been chosen. An order being
 * marked «نواقص» is exactly that moment — it is reachable only from «جديد», and
 * `orders.fulfillment_warehouse_id` is not written until the stock actually leaves.
 *
 * **A sum across sites is a weaker fact than a balance in one, and whoever prints it must say
 * so.** Three hundred spread over three warehouses is not three hundred a foreman can pick from
 * one shelf. This returns the number; naming it «في كل المخازن» is the caller's job.
 *
 * Read-only, and it decides nothing: like its sibling, it is a figure for a person to read, never
 * permission to take anything. Only `recordMovement()` decides that, under a lock.
 *
 * A size with no balance line anywhere is absent from the map rather than zero — "never stocked"
 * and "stocked and empty" are the same answer here, and the caller decides what to call it.
 */
final class OnHandBalancesQuery
{
    /**
     * @param  list<int>  $stockItemIds
     * @return array<int, string>
     */
    public function __invoke(array $stockItemIds): array
    {
        if ($stockItemIds === []) {
            return [];
        }

        $rows = WarehouseStock::query()
            ->whereIn('stock_item_id', $stockItemIds)
            ->selectRaw('stock_item_id, SUM(quantity) as total')
            ->groupBy('stock_item_id')
            ->get();

        $balances = [];

        foreach ($rows as $row) {
            $balances[(int) $row->stock_item_id] = (string) $row->total;
        }

        return $balances;
    }
}
