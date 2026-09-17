<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

use App\Domain\Inventory\DTOs\StockSummary;
use App\Domain\Inventory\Models\Warehouse;

/**
 * The five numbers at the top of a warehouse's balance screen.
 *
 * **One round trip, counted by the database.** Five separate counts would be five queries, and
 * paging the lines into PHP to count them there would be worse still: the summary describes the
 * whole warehouse, not the page somebody is looking at.
 *
 * It also, deliberately, takes no {@see StockFilters}. A summary that narrowed along with the
 * list could not tell anyone what they had narrowed *from* — «٤ من ٢٤» is the sentence, and the
 * ٢٤ has to survive the filter.
 */
final class StockSummaryQuery
{
    public function __invoke(Warehouse $warehouse): StockSummary
    {
        /** @var object{total_lines: int, total_quantity: string|null, low_stock_count: int, out_of_stock_count: int, healthy_count: int}|null $row */
        $row = $warehouse->stocks()
            // The relation orders by size so the list reads in a stable order; an aggregate has
            // no row to order, and Postgres rejects the column outright.
            ->reorder()
            ->selectRaw('COUNT(*) AS total_lines')
            // Summed by the database, where `quantity` is a numeric and stays exact. Rounded
            // through PHP floats it would drift, and a total that disagrees with its own lines
            // is worse than no total.
            ->selectRaw('COALESCE(SUM(quantity), 0) AS total_quantity')
            // `SUM(CASE …)` rather than Postgres's `COUNT(*) FILTER`: the same answer in every
            // engine this has ever been pointed at, and the aggregate is not where cleverness pays.
            ->selectRaw('SUM(CASE WHEN low_stock_threshold IS NOT NULL AND quantity <= low_stock_threshold THEN 1 ELSE 0 END) AS low_stock_count')
            ->selectRaw('SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END) AS out_of_stock_count')
            ->selectRaw('SUM(CASE WHEN quantity > 0 AND (low_stock_threshold IS NULL OR quantity > low_stock_threshold) THEN 1 ELSE 0 END) AS healthy_count')
            ->toBase()
            ->first();

        return new StockSummary(
            totalLines: (int) ($row->total_lines ?? 0),
            // Through bcmath, never a float cast: the sum arrives as an exact numeric string and
            // this is the last place it could quietly stop being one.
            totalQuantity: bcadd((string) ($row->total_quantity ?? '0'), '0', 3),
            lowStockCount: (int) ($row->low_stock_count ?? 0),
            outOfStockCount: (int) ($row->out_of_stock_count ?? 0),
            healthyCount: (int) ($row->healthy_count ?? 0),
        );
    }
}
