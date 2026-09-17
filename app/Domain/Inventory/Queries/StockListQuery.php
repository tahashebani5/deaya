<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * One warehouse's shelves.
 *
 * Always scoped to a warehouse — "every balance in the business" is not a screen, it is a
 * report, and it would page through tens of thousands of rows to answer a question nobody asked
 * in that form.
 */
final class StockListQuery
{
    /**
     * @return LengthAwarePaginator<int, WarehouseStock>
     */
    public function __invoke(Warehouse $warehouse, StockFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $warehouse->stocks()
            // The resource renders the size's label, its product's name and its picture. Strict
            // mode turns a forgotten eager load into an exception rather than one query per row —
            // which is the behaviour we want, but only once, here. The images come in one extra
            // query for the whole page, and the relation already sorts the primary one first.
            ->with('stockItem')
            ->when(
                $filters->stockItemId !== null,
                fn ($q) => $q->where('stock_item_id', $filters->stockItemId),
            )
            ->when(
                $filters->inStock !== null,
                fn ($q) => $filters->inStock ? $q->where('quantity', '>', 0) : $q->where('quantity', '<=', 0),
            )
            ->when($filters->lowStock !== null, function ($q) use ($filters) {
                // A null threshold means nobody asked to be warned, so such a line is neither
                // low nor "not low" — it is outside the question. Both branches therefore
                // require the threshold to be set, rather than one of them inverting the other.
                return $filters->lowStock
                    ? $q->whereNotNull('low_stock_threshold')->whereColumn('quantity', '<=', 'low_stock_threshold')
                    : $q->whereNotNull('low_stock_threshold')->whereColumn('quantity', '>', 'low_stock_threshold');
            })
            ->paginate($perPage);
    }
}
