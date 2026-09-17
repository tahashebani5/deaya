<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

use App\Domain\Inventory\Actions\ConsumeStockBatchesFifo;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\StockBatch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The cost layers behind a balance — the first time anything in this application could read them.
 *
 * **Ordered the way they will be consumed**, `received_at` then `id`, exactly as
 * {@see ConsumeStockBatchesFifo} draws them down. A list sorted any other way would show a
 * storekeeper a queue that is not the queue: the layer at the top is the one the next order takes
 * from, and that is the single most useful fact on the screen.
 *
 * The same ordering is what makes `uncosted=1` a work list rather than a report — the zero-cost
 * layers at the top are the ones about to be drawn into an order at no material cost at all.
 */
final class StockBatchListQuery
{
    /**
     * @return LengthAwarePaginator<int, StockBatch>
     */
    public function __invoke(StockBatchFilters $filters, int $perPage = 20): LengthAwarePaginator
    {
        return StockBatch::query()
            // The resource names the shelf, the warehouse and the movement that opened the
            // layer. Strict mode turns a forgotten load into an exception rather than a query
            // per row.
            ->with(['stockItem', 'warehouse', 'stockMovement'])
            // **The purchase order, as one subquery for the whole page rather than a lookup per
            // row.** It is two hops away — batch → movement → arrival → order — and none of them
            // is an eager-loadable relation: `stock_movements.reference_id` means a different
            // table for each movement type, so a `belongsTo` would be a lie for four of the six.
            // Restricted to an arrival for exactly that reason.
            ->addSelect(['stock_batches.*', 'purchase_order_id' => $this->purchaseOrderSubquery()])
            ->when($filters->warehouseId !== null, fn ($q) => $q->where('warehouse_id', $filters->warehouseId))
            ->when($filters->stockItemId !== null, fn ($q) => $q->where('stock_item_id', $filters->stockItemId))
            ->when(
                $filters->uncosted !== null,
                fn ($q) => $filters->uncosted ? $q->where('unit_cost', '<=', 0) : $q->where('unit_cost', '>', 0),
            )
            // Defaults to the layers that still hold something: a used-up layer cannot be
            // repriced and is not part of any queue, so it is only ever asked for explicitly.
            ->when(
                $filters->remaining ?? true,
                fn ($q) => $q->where('quantity_remaining', '>', 0),
                fn ($q) => $q->where('quantity_remaining', '<=', 0),
            )
            ->orderBy('received_at')
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * @return Builder
     */
    private function purchaseOrderSubquery()
    {
        return DB::table('stock_movements')
            ->join('stock_arrivals', 'stock_arrivals.id', '=', 'stock_movements.reference_id')
            ->whereColumn('stock_movements.id', 'stock_batches.stock_movement_id')
            ->where('stock_movements.movement_type', MovementType::PurchaseArrival->value)
            ->whereNull('stock_arrivals.deleted_at')
            ->select('stock_arrivals.purchase_order_id')
            ->limit(1);
    }
}
