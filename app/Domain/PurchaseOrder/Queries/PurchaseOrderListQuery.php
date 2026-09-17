<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Queries;

use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\PurchaseOrder\Queries\Concerns\FiltersPurchaseOrders;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The purchase-order list: filterable by vendor, warehouse and status, newest first.
 *
 * The status filter takes a group — see {@see PurchaseOrderFilters} — because «أوامر الشراء
 * الجارية» is `new` and `arrived` together, which is one question and must be one call.
 */
final class PurchaseOrderListQuery
{
    use FiltersPurchaseOrders;

    /**
     * @return LengthAwarePaginator<int, PurchaseOrder>
     */
    public function __invoke(PurchaseOrderFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->applyFilters(PurchaseOrder::query(), $filters)
            ->with(['vendor', 'warehouse', 'items.stockItem'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
