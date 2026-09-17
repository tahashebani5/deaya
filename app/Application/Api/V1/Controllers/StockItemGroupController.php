<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Controllers\Concerns\ReadsAuditTrail;
use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\Inventory\StoreStockItemGroupRequest;
use App\Application\Api\V1\Requests\Inventory\UpdateStockItemGroupRequest;
use App\Application\Api\V1\Resources\StockItemGroupResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Domain\Inventory\DTOs\StockItemGroupData;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\StockItemGroup;
use App\Domain\Inventory\Queries\StockItemGroupFilters;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stock item groups — «التصنيف», the family a material is filed under.
 *
 * «كيس شحن» is a material; «كيس شحن 25*35» and «كيس شحن 35*40» are two of its sizes, and those
 * are the things a warehouse actually holds. **A group holds nothing** — no balance, no cost
 * layer, no size.
 *
 * **What it is for.** A product names its material once, through `stock_item_group_id`, and every
 * size it carries then resolves to the shelf of that material at the same size — created on the
 * spot if the material has not reached that size yet. Nobody picks a shelf size by size, and two
 * products made of the same material land on the same piles automatically.
 *
 * Reading needs `inventory.view`, changing the list needs `inventory.manage` — the same pair that
 * covers warehouses, stock items, balances and the ledger.
 */
class StockItemGroupController extends Controller
{
    use ReadsAuditTrail, ResponseTrait;

    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * List stock item groups
     *
     * `search` matches the material's name. Each row carries `items_count` — how many sizes it
     * comes in — and `products_count`, how many products are made of it.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = StockItemGroupFilters::fromArray($request->only(['search', 'is_active']));
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        return $this->successWithPagination(
            StockItemGroupResource::collection($this->inventory->paginateStockItemGroups($filters, $perPage)),
        );
    }

    /**
     * Create a stock item group
     *
     * It starts with no sizes at all, and that is the normal case: sizes arrive as products name
     * them. There is no need to enumerate a material's sizes before using it.
     *
     * `default_unit` is what a size created under it starts out counted in — not the authority for
     * a shelf that already exists.
     */
    public function store(StoreStockItemGroupRequest $request): JsonResponse
    {
        $group = $this->inventory->createStockItemGroup(
            StockItemGroupData::fromArray($request->validated()),
        );

        return $this->created(new StockItemGroupResource($group), 'تم إضافة التصنيف بنجاح');
    }

    /**
     * Get one stock item group
     *
     * Carries its sizes, smallest first.
     */
    public function show(StockItemGroup $stockItemGroup): JsonResponse
    {
        return $this->success(new StockItemGroupResource(
            $stockItemGroup->load('items')->loadCount(['items', 'products']),
        ));
    }

    /**
     * Update a stock item group
     *
     * **Renaming renames every size of it**, in the same transaction — a grouped stock item
     * carries its material's name, and that is what lets `(name, size)` identify exactly one
     * shelf.
     *
     * Changing `default_unit` affects only sizes created afterwards. An existing shelf's unit
     * moves through `PATCH /stock-items/{stock_item}/unit`, which restamps its balances and cost
     * layers under locks.
     */
    public function update(UpdateStockItemGroupRequest $request, StockItemGroup $stockItemGroup): JsonResponse
    {
        $updated = $this->inventory->updateStockItemGroup(
            $stockItemGroup,
            StockItemGroupData::fromArray($request->validated()),
        );

        return $this->success(new StockItemGroupResource($updated), 'تم تحديث التصنيف بنجاح');
    }

    /**
     * Delete a stock item group
     *
     * Refused with 422 while any size or any product still points at it. Both foreign keys are
     * `nullOnDelete`, so allowing it would not orphan a row — it would quietly strip the rule that
     * files a product's sizes, and the next save would detach every one of them from its shelf.
     *
     * Soft, like every delete here.
     */
    public function destroy(StockItemGroup $stockItemGroup): JsonResponse
    {
        $this->inventory->deleteStockItemGroup($stockItemGroup);

        return $this->successMessage('تم حذف التصنيف بنجاح');
    }

    /**
     * A stock item group's history
     *
     * Every change to the material and to its sizes, newest first — «من غيّر وحدة 25*35؟» is
     * asked of the material, and that number lives on another table.
     *
     * Not the balances or the ledger: one is read on the size's own screen, the other through
     * `GET /stock-movements?stock_item_id=`.
     */
    public function logs(ActivityLogFilterRequest $request, StockItemGroup $stockItemGroup, AuditService $audit): JsonResponse
    {
        return $this->auditTrailResponse($request, $stockItemGroup, $audit);
    }
}
