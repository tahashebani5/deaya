<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Models\WarehouseStock;

/**
 * The one thing a person edits on a balance row.
 *
 * `quantity` is not settable from anywhere — it is written by {@see ApplyStockChange} alone,
 * inside the transaction that records why. The threshold is the opposite kind of value: a
 * preference somebody chose, with no ledger entry to make, so it is a plain update.
 *
 * Passing null clears the warning rather than setting it to zero. Zero would mean "warn me when
 * this reaches nothing", which is a real thing to ask for and must stay distinguishable from
 * "do not warn me about this line".
 */
final class SetLowStockThreshold
{
    public function __invoke(WarehouseStock $stock, ?string $threshold): WarehouseStock
    {
        $stock->update(['low_stock_threshold' => $threshold]);

        // Down to the pictures, because the resource renders one. Loading the product but not
        // its images would leave the last hop to be fetched lazily — an extra query per update
        // in production, and a strict-mode exception everywhere else.
        return $stock->load('stockItem');
    }
}
