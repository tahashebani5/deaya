<?php

declare(strict_types=1);

namespace App\Domain\Vendor\Queries;

use App\Domain\Inventory\Queries\MovementListQuery;
use App\Domain\Vendor\Models\StockArrival;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reading a vendor's purchase history — the same purpose-built reader
 * {@see MovementListQuery} is for the stock ledger, and the reason
 * `Vendor::auditTrailSubjects()` does not try to pluck every arrival id into an `IN` clause.
 */
final class StockArrivalListQuery
{
    /**
     * @return LengthAwarePaginator<int, StockArrival>
     */
    public function __invoke(StockArrivalFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return StockArrival::query()
            ->with(['vendor', 'warehouse', 'receivedByUser', 'items.stockItem'])
            ->when($filters->vendorId !== null, fn (Builder $q) => $q->where('vendor_id', $filters->vendorId))
            ->when($filters->warehouseId !== null, fn (Builder $q) => $q->where('warehouse_id', $filters->warehouseId))
            ->when($filters->from !== null, fn (Builder $q) => $q->where('created_at', '>=', $filters->from))
            ->when($filters->to !== null, fn (Builder $q) => $q->where('created_at', '<=', $filters->to))
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
