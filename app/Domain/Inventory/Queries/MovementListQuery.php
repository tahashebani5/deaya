<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

use App\Domain\Inventory\Models\StockMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Reading the stock ledger.
 *
 * This is where a warehouse's history is actually read — deliberately not through
 * `Warehouse::auditTrailSubjects()`, which would have to pluck every movement id it has ever
 * been part of into an `IN` clause that grows for as long as the business trades. Here the
 * filter is a `where` against an index, and pages properly.
 *
 * **Three things are computed on the way out, and each is conditional on what was asked:**
 *
 *   * `signed_quantity` — when the reader named a warehouse. A transfer is `+200` on the shelf
 *     that received it and `-200` on the one that sent it; the same row, read from two places.
 *     Unscoped, there is no sign to give.
 *   * `balance_after` — when the reader named a warehouse *and* a shelf: what that shelf held
 *     once this row had happened. A correlated sum over the whole history up to this row rather
 *     than a window over the page, so a filter that hides rows never changes the balance on the
 *     rows it shows, and page two agrees with page one.
 *   * the cost columns — when the reader may see them (`$withCost`). Two aggregates per row:
 *     the layers this movement opened (an arrival's price) and the layers it drew down (what
 *     FIFO charged an issue). Skipped entirely for a reader who may not be told.
 */
final class MovementListQuery
{
    /**
     * @return LengthAwarePaginator<int, StockMovement>
     */
    public function __invoke(MovementFilters $filters, int $perPage = 15, bool $withCost = false): LengthAwarePaginator
    {
        $query = StockMovement::query()
            ->select('stock_movements.*')
            // Every row renders the size, both ends and who moved it. Strict mode makes a
            // forgotten eager load throw rather than fire four queries per row.
            ->with(['stockItem', 'fromWarehouse', 'toWarehouse', 'employee'])
            ->when($filters->warehouseId !== null, function (Builder $q) use ($filters) {
                // Either end — see MovementFilters. Grouped so the OR set cannot escape and
                // swallow the date and type filters beside it.
                $q->where(function (Builder $q) use ($filters) {
                    $q->where('from_warehouse_id', $filters->warehouseId)
                        ->orWhere('to_warehouse_id', $filters->warehouseId);
                });
            })
            ->when(
                $filters->stockItemId !== null,
                fn (Builder $q) => $q->where('stock_item_id', $filters->stockItemId),
            )
            ->when(
                $filters->movementType !== null,
                fn (Builder $q) => $q->where('movement_type', $filters->movementType?->value),
            )
            ->when(
                $filters->adjustmentReason !== null,
                fn (Builder $q) => $q->where('adjustment_reason', $filters->adjustmentReason?->value),
            )
            ->when($filters->employeeId !== null, fn (Builder $q) => $q->where('employee_id', $filters->employeeId))
            ->when($filters->referenceId !== null, fn (Builder $q) => $q->where('reference_id', $filters->referenceId))
            ->when($filters->from !== null, fn (Builder $q) => $q->where('created_at', '>=', $filters->from))
            ->when($filters->to !== null, fn (Builder $q) => $q->where('created_at', '<=', $filters->to));

        if ($filters->warehouseId !== null) {
            $query->selectRaw(
                'CASE WHEN stock_movements.to_warehouse_id = ? THEN stock_movements.quantity'
                .' ELSE -stock_movements.quantity END AS signed_quantity',
                [$filters->warehouseId],
            );

            if ($filters->stockItemId !== null) {
                $query->addSelect([
                    'balance_after' => $this->balanceSubquery($filters->warehouseId, $filters->stockItemId),
                ]);
            }
        }

        if ($withCost) {
            $query->addSelect([
                'batch_quantity' => $this->openedLayers('SUM(quantity_received)'),
                'batch_total_cost' => $this->openedLayers('SUM(quantity_received * unit_cost)'),
                'batch_uncosted_quantity' => $this->openedLayers(
                    'SUM(CASE WHEN unit_cost <= 0 THEN quantity_received ELSE 0 END)',
                ),
                'consumed_quantity' => $this->drawnLayers('SUM(quantity)'),
                'consumed_total_cost' => $this->drawnLayers('SUM(total_cost)'),
                'consumed_uncosted_quantity' => $this->drawnLayers(
                    'SUM(CASE WHEN unit_cost <= 0 THEN quantity ELSE 0 END)',
                ),
            ]);
        }

        return $query
            // Newest first, and by id rather than created_at: a transfer writes its two balance
            // updates and its ledger row inside one transaction, so several movements recorded
            // together share a timestamp to the second and ordering by it alone would shuffle
            // them between pages. The id is the only total order there is.
            ->orderByDesc('stock_movements.id')
            ->paginate($perPage);
    }

    /**
     * The shelf's balance once this row had happened: everything that ever arrived on it, less
     * everything that ever left, counting only rows up to and including this one. Ordered by
     * id for the same reason the page is — it is the only total order the ledger has.
     */
    private function balanceSubquery(int $warehouseId, int $stockItemId): QueryBuilder
    {
        return DB::table('stock_movements as history')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN history.to_warehouse_id = ? THEN history.quantity'
                .' ELSE -history.quantity END), 0)',
                [$warehouseId],
            )
            ->where('history.stock_item_id', $stockItemId)
            ->where(function (QueryBuilder $q) use ($warehouseId) {
                $q->where('history.from_warehouse_id', $warehouseId)
                    ->orWhere('history.to_warehouse_id', $warehouseId);
            })
            ->whereNull('history.deleted_at')
            ->whereColumn('history.id', '<=', 'stock_movements.id');
    }

    /**
     * The cost layers this movement opened — an arrival's, or an upward adjustment's.
     *
     * **Pinned to the movement's own destination.** A batch relocated by a transfer keeps the
     * `stock_movement_id` of the arrival it came from (so it does not get younger), which means
     * the arrival's id is on batches in two warehouses once part of it has moved; only the ones
     * still on the shelf it arrived at are the arrival. `quantity_received` rather than what
     * remains, because the question is what this row cost, not what is left of it — and a
     * revaluation that split the layer moved `quantity_received` across with the split, so the
     * sum is still the arrival.
     */
    private function openedLayers(string $aggregate): QueryBuilder
    {
        return DB::table('stock_batches')
            ->selectRaw($aggregate)
            ->whereColumn('stock_batches.stock_movement_id', 'stock_movements.id')
            ->whereColumn('stock_batches.warehouse_id', 'stock_movements.to_warehouse_id')
            ->whereNull('stock_batches.deleted_at');
    }

    /**
     * The cost layers this movement drew down — what FIFO charged an issue, a transfer, a scrap
     * or a downward count. Each consumption froze the unit cost of the layer it took from, so
     * `total_cost` here is what the stock on this row was actually carried at.
     */
    private function drawnLayers(string $aggregate): QueryBuilder
    {
        return DB::table('stock_batch_consumptions')
            ->selectRaw($aggregate)
            ->whereColumn('stock_batch_consumptions.stock_movement_id', 'stock_movements.id')
            ->whereNull('stock_batch_consumptions.deleted_at');
    }
}
