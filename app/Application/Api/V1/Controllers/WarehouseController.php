<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Controllers\Concerns\ReadsAuditTrail;
use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\Inventory\StoreWarehouseRequest;
use App\Application\Api\V1\Requests\Inventory\UpdateWarehouseRequest;
use App\Application\Api\V1\Resources\WarehouseResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Domain\Inventory\DTOs\WarehouseData;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Queries\WarehouseFilters;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Warehouses
 *
 * The places stock is held — the main store and the workshop floor. `type` says which,
 * and today it only labels and groups a row in a picker; nothing branches on it.
 *
 * Reading needs `inventory.view`, because anyone taking an order needs to know where stock is.
 * Changing the list needs `inventory.manage`. Both are declared on the routes, so the guard on an
 * endpoint is visible without opening this file.
 *
 * **No endpoint here moves stock.** Balances change only by recording a movement — see
 * `/stock-movements`.
 */
class WarehouseController extends Controller
{
    use ReadsAuditTrail, ResponseTrait;

    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * List warehouses
     *
     * In creation order, which starts with the main store. `search` matches the name or the
     * location. Each row carries `stocks_count` — how many distinct sizes have a balance line
     * here — rather than the balances themselves; fetch `/warehouses/{warehouse}/stocks` for those.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = WarehouseFilters::fromArray($request->only(['search', 'type']));
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        return $this->successWithPagination(
            WarehouseResource::collection($this->inventory->paginateWarehouses($filters, $perPage)),
        );
    }

    /**
     * Create a warehouse
     *
     * It starts empty. Stock arrives through `/stock-movements/arrivals`.
     */
    public function store(StoreWarehouseRequest $request): JsonResponse
    {
        $warehouse = $this->inventory->createWarehouse(WarehouseData::fromArray($request->validated()));

        return $this->created(new WarehouseResource($warehouse), 'تم إضافة المخزن بنجاح');
    }

    /**
     * Get one warehouse
     */
    public function show(Warehouse $warehouse): JsonResponse
    {
        return $this->success(new WarehouseResource($warehouse->loadCount('stocks')));
    }

    /**
     * Update a warehouse
     *
     * The whole warehouse is replaced by what is sent, so leaving `location` out clears it rather
     * than keeping it. Retyping a warehouse changes what the place is called, never what is in it.
     */
    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): JsonResponse
    {
        $updated = $this->inventory->updateWarehouse($warehouse, WarehouseData::fromArray($request->validated()));

        return $this->success(new WarehouseResource($updated), 'تم تحديث المخزن بنجاح');
    }

    /**
     * Delete a warehouse
     *
     * Its balance lines go too — they describe what is in *this* place.
     *
     * Refused with 422 while any of those lines is non-zero: stock inside a warehouse the API
     * says does not exist is a number nobody can reconcile. Move it or write it off first, both
     * of which leave a record. Lines sitting at zero do not block it.
     *
     * The movements that passed through stay readable — a transfer that happened on a Tuesday is
     * still true afterwards, and the warehouse at the other end would otherwise develop a hole
     * in its history.
     */
    public function destroy(Warehouse $warehouse): JsonResponse
    {
        $this->inventory->deleteWarehouse($warehouse);

        return $this->successMessage('تم حذف المخزن بنجاح');
    }

    /**
     * A warehouse's history
     *
     * Every change to the warehouse itself and to the alert thresholds set on its shelves,
     * newest first — who made it and what it was before.
     *
     * **The stock movements are not here, deliberately.** They are a ledger, not a change log:
     * they grow for as long as the business trades, and they have a reader built for them at
     * `GET /stock-movements?warehouse_id=…` which pages and filters properly.
     *
     * Filter with `event`, `causer_id`, `from` and `to`.
     */
    public function logs(ActivityLogFilterRequest $request, Warehouse $warehouse, AuditService $audit): JsonResponse
    {
        return $this->auditTrailResponse($request, $warehouse, $audit);
    }
}
