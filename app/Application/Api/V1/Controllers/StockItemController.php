<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Controllers\Concerns\ReadsAuditTrail;
use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\Inventory\SetStockItemUnitRequest;
use App\Application\Api\V1\Requests\Inventory\SetStockItemVariantsRequest;
use App\Application\Api\V1\Requests\Inventory\StoreStockItemRequest;
use App\Application\Api\V1\Requests\Inventory\UpdateStockItemRequest;
use App\Application\Api\V1\Resources\StockItemResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\DTOs\StockItemData;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Queries\StockItemFilters;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stock items
 *
 * What a warehouse actually holds — «كيس شحن 25*35». A stock item is a material *at a size*, and
 * it is what every balance, ledger entry, cost layer and purchase order line is keyed on.
 *
 * **Many product sizes share one.** كيس شحن سادة 25*35 and كيس شحن مطبوع 25*35 are two catalogue
 * rows and one pile of bags: both point at the same stock item, draw down the same balance, and
 * consume the same FIFO cost layers. What separates them is the printing, which is a
 * manufacturing rate, not a different material.
 *
 * Sharing runs across products at one size, never across sizes: 25*35 and 35*40 are two items,
 * two shelves and two prices, bought on two purchase order lines.
 *
 * Reading needs `inventory.view`, changing the list needs `inventory.manage` — the same pair that
 * covers warehouses, balances and the ledger, because maintaining the shelves and administering
 * what sits on them are the same job.
 *
 * **No endpoint here moves stock.** Balances change only by recording a movement — see
 * `/stock-movements`.
 */
class StockItemController extends Controller
{
    use ReadsAuditTrail, ResponseTrait;

    public function __construct(
        private readonly InventoryService $inventory,
        // `product_variants` is Catalog's table, and `setVariants` writes it — so it is asked for
        // through Catalog's own door rather than reached around (RULES.md §3).
        private readonly CatalogService $catalog,
    ) {}

    /**
     * List stock items
     *
     * Grouped by name so every size of one material reads together. `search` matches the name;
     * `width_cm` and `height_cm` narrow to one size, which is what the picker on a product's size
     * uses to offer the matching shelves first.
     *
     * Each row carries `variants_count` — how many product sizes draw on it — rather than the
     * sizes themselves.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = StockItemFilters::fromArray(
            $request->only(['search', 'is_active', 'width_cm', 'height_cm']),
        );
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        return $this->successWithPagination(
            StockItemResource::collection($this->inventory->paginateStockItems($filters, $perPage)),
        );
    }

    /**
     * Create a stock item
     *
     * It starts empty and in no warehouse. Stock arrives through `/stock-movements/arrivals` or a
     * received purchase order.
     *
     * `unit` is required and cannot be changed by a later update — see
     * `PATCH /stock-items/{stock_item}/unit`.
     */
    public function store(StoreStockItemRequest $request): JsonResponse
    {
        $item = $this->inventory->createStockItem(StockItemData::fromArray($request->validated()));

        return $this->created(new StockItemResource($item), 'تم إضافة المادة بنجاح');
    }

    /**
     * Get one stock item
     */
    public function show(StockItem $stockItem): JsonResponse
    {
        // The sizes as well as their count, which the listing deliberately does not carry: this is
        // the one response a screen can edit the links from, and it has to draw what is there now.
        return $this->success(
            new StockItemResource(
                $stockItem
                    // The suggestion the arrival form offers. Loaded here and on no listing:
                    // see StockItemResource for why.
                    ->load(['variants.product', 'latestCostedBatch'])
                    ->loadCount('variants'),
            ),
        );
    }

    /**
     * Update a stock item
     *
     * Everything except the unit. Renaming or resizing is safe — neither is snapshotted anywhere —
     * but the name must stay unique for its size.
     */
    public function update(UpdateStockItemRequest $request, StockItem $stockItem): JsonResponse
    {
        $updated = $this->inventory->updateStockItem(
            $stockItem,
            StockItemData::fromArray($request->validated()),
        );

        return $this->success(new StockItemResource($updated), 'تم تحديث المادة بنجاح');
    }

    /**
     * Set which product sizes draw on a stock item
     *
     * **The list replaces what was there.** Sizes in it are pointed at this material, sizes that
     * were on it and are missing from it are unlinked, and `[]` empties it deliberately. Sending
     * the whole set is what lets one screen say «these four and no others» in one request instead
     * of editing four products.
     *
     * **Moving a size off another material is allowed.** Nothing already in the ledger follows it
     * — a movement is keyed on the material, not on the size that caused it — so only what this
     * size deducts from next changes. The screen confirms that by name before sending it.
     *
     * Every link that actually moves is written to the audit trail, one variant at a time.
     */
    public function setVariants(SetStockItemVariantsRequest $request, StockItem $stockItem): JsonResponse
    {
        $updated = $this->catalog->pointVariantsAtStockItem(
            $stockItem,
            array_map(intval(...), $request->validated('variant_ids')),
        );

        return $this->success(new StockItemResource($updated), 'تم تحديث المقاسات المرتبطة بنجاح');
    }

    /**
     * Set what a stock item is counted in
     *
     * Independent of any product's `pricing_unit` — what the customer is charged by. A thing
     * bought in by weight and sold by the piece needs the two to differ.
     *
     * **Declares the unit, it does not convert quantities.** Whatever is already on the shelf
     * keeps its number: it was correct in its own unit before and stays correct after. Every
     * existing balance and cost layer for this item is restamped in the same transaction, under
     * the same locks a stock movement takes, so nothing can end up in one unit beside something
     * in another.
     */
    public function setUnit(SetStockItemUnitRequest $request, StockItem $stockItem): JsonResponse
    {
        $updated = $this->inventory->setStockItemUnit(
            $stockItem,
            PricingUnit::from($request->string('unit')->toString()),
            // Named on every adjustment this writes: discarding a shelf is an act, and the
            // ledger records who performed it exactly as it does for any other movement.
            (int) $request->user()->getKey(),
        );

        return $this->success(new StockItemResource($updated), 'تم تحديث وحدة التخزين بنجاح');
    }

    /**
     * Delete a stock item
     *
     * Refused with 422 while any warehouse still holds a quantity of it — stock inside an item the
     * API says does not exist is a balance nobody can reconcile — and refused again while any
     * product size still draws on it, which would otherwise cut the link silently and surface
     * weeks later as an order that cannot be fulfilled.
     *
     * Soft, like every delete here: the balances that reached zero, the ledger explaining them and
     * the item's own history all survive.
     */
    public function destroy(StockItem $stockItem): JsonResponse
    {
        $this->inventory->deleteStockItem($stockItem);

        return $this->successMessage('تم حذف المادة بنجاح');
    }

    /**
     * A stock item's history
     *
     * Every change to the item and to the alert thresholds set on its shelves, newest first.
     *
     * **Not the movements.** Those are a ledger rather than a change log, and one item's grows for
     * as long as the business trades — `GET /stock-movements?stock_item_id=` pages and filters
     * them properly.
     */
    public function logs(ActivityLogFilterRequest $request, StockItem $stockItem, AuditService $audit): JsonResponse
    {
        return $this->auditTrailResponse($request, $stockItem, $audit);
    }
}
