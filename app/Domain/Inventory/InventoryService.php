<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Catalog\Actions\SyncProductVariants;
use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Actions\CreateStockItem;
use App\Domain\Inventory\Actions\CreateStockItemGroup;
use App\Domain\Inventory\Actions\CreateWarehouse;
use App\Domain\Inventory\Actions\DeleteStockItem;
use App\Domain\Inventory\Actions\DeleteStockItemGroup;
use App\Domain\Inventory\Actions\DeleteWarehouse;
use App\Domain\Inventory\Actions\RecordStockMovement;
use App\Domain\Inventory\Actions\ResolveStockItemForVariant;
use App\Domain\Inventory\Actions\RevalueStockBatch;
use App\Domain\Inventory\Actions\SetLowStockThreshold;
use App\Domain\Inventory\Actions\SetStockItemUnit;
use App\Domain\Inventory\Actions\UpdateStockItem;
use App\Domain\Inventory\Actions\UpdateStockItemGroup;
use App\Domain\Inventory\Actions\UpdateWarehouse;
use App\Domain\Inventory\DTOs\StockItemData;
use App\Domain\Inventory\DTOs\StockItemGroupData;
use App\Domain\Inventory\DTOs\StockMovementData;
use App\Domain\Inventory\DTOs\StockSummary;
use App\Domain\Inventory\DTOs\WarehouseData;
use App\Domain\Inventory\Exceptions\BatchIsFullyConsumed;
use App\Domain\Inventory\Exceptions\RevaluationExceedsRemaining;
use App\Domain\Inventory\Exceptions\VariantHasNoStockItem;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockItemGroup;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Queries\ConsumptionBreakdownQuery;
use App\Domain\Inventory\Queries\FindStockItem;
use App\Domain\Inventory\Queries\FindStockItemGroup;
use App\Domain\Inventory\Queries\MovementFilters;
use App\Domain\Inventory\Queries\MovementListQuery;
use App\Domain\Inventory\Queries\OnHandBalancesQuery;
use App\Domain\Inventory\Queries\StockBatchFilters;
use App\Domain\Inventory\Queries\StockBatchListQuery;
use App\Domain\Inventory\Queries\StockFilters;
use App\Domain\Inventory\Queries\StockItemFilters;
use App\Domain\Inventory\Queries\StockItemGroupFilters;
use App\Domain\Inventory\Queries\StockItemGroupListQuery;
use App\Domain\Inventory\Queries\StockItemListQuery;
use App\Domain\Inventory\Queries\StockListQuery;
use App\Domain\Inventory\Queries\StockSummaryQuery;
use App\Domain\Inventory\Queries\WarehouseBalancesQuery;
use App\Domain\Inventory\Queries\WarehouseFilters;
use App\Domain\Inventory\Queries\WarehouseListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The Inventory module's public front door.
 *
 * Orders draws stock down by calling `recordMovement()` here — never by writing a balance itself.
 * That is the same seam Catalog has for pricing, and it exists for the same reason: the number on
 * the shelf and the reason it changed are produced by one piece of code, so they cannot disagree.
 *
 * **There is deliberately no `setQuantity()`.** A balance moves because something happened, and
 * the something is the argument. A method that took a number and wrote it would be a way to
 * change stock without saying why, which is the one thing this context is built to prevent — a
 * stocktake correction is an adjustment movement, and it leaves a row behind like everything else.
 *
 * This module depends on Catalog (to read a size's label, and to resolve which shelf it draws
 * from) and on Identity (to name an employee). Neither depends on it, and neither should: a
 * product does not need to know it is stocked.
 */
class InventoryService
{
    public function __construct(
        private readonly CreateWarehouse $createWarehouse,
        private readonly UpdateWarehouse $updateWarehouse,
        private readonly DeleteWarehouse $deleteWarehouse,
        private readonly CreateStockItem $createStockItem,
        private readonly UpdateStockItem $updateStockItem,
        private readonly DeleteStockItem $deleteStockItem,
        private readonly SetStockItemUnit $setStockItemUnit,
        private readonly CreateStockItemGroup $createStockItemGroup,
        private readonly UpdateStockItemGroup $updateStockItemGroup,
        private readonly DeleteStockItemGroup $deleteStockItemGroup,
        private readonly ResolveStockItemForVariant $resolveStockItemForVariant,
        private readonly FindStockItemGroup $findStockItemGroup,
        private readonly StockItemGroupListQuery $stockItemGroupListQuery,
        private readonly RecordStockMovement $recordStockMovement,
        private readonly SetLowStockThreshold $setLowStockThreshold,
        private readonly WarehouseListQuery $warehouseListQuery,
        private readonly StockItemListQuery $stockItemListQuery,
        private readonly FindStockItem $findStockItem,
        private readonly ConsumptionBreakdownQuery $consumptionBreakdown,
        private readonly StockListQuery $stockListQuery,
        private readonly StockSummaryQuery $stockSummaryQuery,
        private readonly WarehouseBalancesQuery $warehouseBalancesQuery,
        private readonly OnHandBalancesQuery $onHandBalancesQuery,
        private readonly MovementListQuery $movementListQuery,
        private readonly StockBatchListQuery $stockBatchListQuery,
        private readonly RevalueStockBatch $revalueStockBatch,
    ) {}

    // ── warehouses ──────────────────────────────────────────────────────────────────────

    /**
     * @return LengthAwarePaginator<int, Warehouse>
     */
    public function paginateWarehouses(WarehouseFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return ($this->warehouseListQuery)($filters, $perPage);
    }

    public function createWarehouse(WarehouseData $data): Warehouse
    {
        return ($this->createWarehouse)($data);
    }

    public function updateWarehouse(Warehouse $warehouse, WarehouseData $data): Warehouse
    {
        return ($this->updateWarehouse)($warehouse, $data);
    }

    /**
     * The balance lines inside go with it — they describe what is in *this* place.
     *
     * Refused while any of them is non-zero: stock inside a deleted warehouse is a number nobody
     * can reconcile. Soft, like every delete here, so the trail records who removed it and the
     * movements that passed through it stay readable.
     */
    public function deleteWarehouse(Warehouse $warehouse): void
    {
        ($this->deleteWarehouse)($warehouse);
    }

    // ── stock item groups ───────────────────────────────────────────────────────────────
    // «التصنيف» — the family a material is filed under. Holds nothing itself; it is
    // what lets a product name its material once instead of picking a shelf per size.

    /**
     * @return LengthAwarePaginator<int, StockItemGroup>
     */
    public function paginateStockItemGroups(StockItemGroupFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return ($this->stockItemGroupListQuery)($filters, $perPage);
    }

    public function findStockItemGroup(int $groupId): StockItemGroup
    {
        return ($this->findStockItemGroup)($groupId);
    }

    public function createStockItemGroup(StockItemGroupData $data): StockItemGroup
    {
        return ($this->createStockItemGroup)($data);
    }

    /**
     * Renaming a material renames every size of it, in the same transaction — see
     * {@see UpdateStockItemGroup} for why that is required rather than tidy.
     */
    public function updateStockItemGroup(StockItemGroup $group, StockItemGroupData $data): StockItemGroup
    {
        return ($this->updateStockItemGroup)($group, $data);
    }

    /**
     * Refused while any size or any product still points at it.
     */
    public function deleteStockItemGroup(StockItemGroup $group): void
    {
        ($this->deleteStockItemGroup)($group);
    }

    /**
     * The shelf a product's size draws from, given the material the product is made of —
     * creating that size if the material has not reached it yet. See
     * {@see ResolveStockItemForVariant}.
     *
     * The one method Catalog calls on its way through {@see SyncProductVariants},
     * and the reason it is here rather than there: which shelf exists, and what a new one is
     * counted in, are Inventory's to decide. A cross-context decision goes through the Service
     * (RULES.md §3), never around it.
     */
    public function resolveStockItemForVariant(int $groupId, ?int $widthCm, ?int $heightCm): StockItem
    {
        return ($this->resolveStockItemForVariant)(
            ($this->findStockItemGroup)($groupId),
            $widthCm,
            $heightCm,
        );
    }

    // ── stock items ─────────────────────────────────────────────────────────────────────
    // What a shelf actually holds — «كيس شحن 25*35». Many product sizes, across products, draw
    // from one of these; that is the whole reason the table exists.

    /**
     * @return LengthAwarePaginator<int, StockItem>
     */
    public function paginateStockItems(StockItemFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return ($this->stockItemListQuery)($filters, $perPage);
    }

    /**
     * One shelf by id, or a 404. The read another context uses when it holds only an id.
     */
    public function findStockItem(int $stockItemId): StockItem
    {
        return ($this->findStockItem)($stockItemId);
    }

    public function createStockItem(StockItemData $data): StockItem
    {
        return ($this->createStockItem)($data);
    }

    /**
     * Everything except the unit — that moves through {@see SetStockItemUnit()}, which carries
     * every balance and batch snapshotted against it along in the same transaction.
     */
    public function updateStockItem(StockItem $item, StockItemData $data): StockItem
    {
        return ($this->updateStockItem)($item, $data);
    }

    /**
     * Refused while any warehouse still holds a quantity of it, and while any product size still
     * draws from it. See {@see DeleteStockItem} for why both, and in that order.
     */
    public function deleteStockItem(StockItem $item): void
    {
        ($this->deleteStockItem)($item);
    }

    /**
     * Declares what this shelf is counted in — **discarding whatever is on it**, because a
     * quantity counted in one unit means nothing in another. See {@see SetStockItemUnit}.
     *
     * Replaces the old per-product `setStockUnit()`: a unit is a fact about the pile, and two
     * products sharing one pile must not be able to disagree about it.
     */
    public function setStockItemUnit(StockItem $item, PricingUnit $unit, int $actorId): StockItem
    {
        return ($this->setStockItemUnit)($item, $unit, $actorId);
    }

    /**
     * Which shelf this size draws from.
     *
     * The one question Order asks on its way to a movement, and the reason it is a method rather
     * than a relation reach-through: `stock_item_id` is nullable, so "this size has no shelf" is a
     * real state that has to be *said*, in Arabic, instead of dereferenced into a 500. See
     * {@see VariantHasNoStockItem}.
     *
     * Returns the item rather than its id because both callers need its name too — a shortfall
     * has to say which pile it is short of, and the pile is what the storekeeper looks for.
     *
     * @throws VariantHasNoStockItem
     */
    public function stockItemFor(ProductVariant $variant): StockItem
    {
        $item = $variant->loadMissing('stockItem')->stockItem;

        if ($item === null) {
            $variant->loadMissing('product');

            throw VariantHasNoStockItem::make(trim("{$variant->product->name} — {$variant->label}"));
        }

        return $item;
    }

    // ── balances ────────────────────────────────────────────────────────────────────────

    /**
     * One warehouse's shelves.
     *
     * @return LengthAwarePaginator<int, WarehouseStock>
     */
    public function paginateStocks(Warehouse $warehouse, StockFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return ($this->stockListQuery)($warehouse, $filters, $perPage);
    }

    /**
     * The same shelves in five numbers — how many items, how much in total, and how many of them
     * are asking for attention. Counted by the database over the whole warehouse, never over the
     * page somebody happens to be looking at.
     */
    public function summariseStocks(Warehouse $warehouse): StockSummary
    {
        return ($this->stockSummaryQuery)($warehouse);
    }

    /**
     * How much of each named item one warehouse holds, in one query.
     *
     * The read another context uses to *say* something about stock — an order naming every item
     * it is short of before it tries to take any. It is not permission to take it: only
     * `recordMovement()` decides that, under a lock. Items with no balance line here are absent
     * from the map rather than zero.
     *
     * @param  list<int>  $stockItemIds
     * @return array<int, string>
     */
    public function balancesFor(int $warehouseId, array $stockItemIds): array
    {
        return ($this->warehouseBalancesQuery)($warehouseId, $stockItemIds);
    }

    /**
     * The same question asked of the whole business rather than one site.
     *
     * For the screen that has to report what is on hand before a warehouse has been chosen — see
     * {@see OnHandBalancesQuery}, which also explains why a sum across sites is the weaker fact
     * and has to be labelled as one.
     *
     * @param  list<int>  $stockItemIds
     * @return array<int, string>
     */
    public function onHandFor(array $stockItemIds): array
    {
        return ($this->onHandBalancesQuery)($stockItemIds);
    }

    /**
     * Set or clear the level someone wants to be warned at. Null clears it.
     */
    public function setLowStockThreshold(WarehouseStock $stock, ?string $threshold): WarehouseStock
    {
        return ($this->setLowStockThreshold)($stock, $threshold);
    }

    // ── the ledger ──────────────────────────────────────────────────────────────────────

    /**
     * `$withCost` is whether the reader holds `inventory.view_cost`: the cost columns are
     * two subqueries per row that are not run for someone who may not be told the answer.
     *
     * @return LengthAwarePaginator<int, StockMovement>
     */
    public function paginateMovements(MovementFilters $filters, int $perPage = 15, bool $withCost = false): LengthAwarePaginator
    {
        return ($this->movementListQuery)($filters, $perPage, $withCost);
    }

    /**
     * Record a movement and apply it to the balances it affects, atomically.
     *
     * The only way stock ever moves. Every movement type shares this one entry point because they
     * differ only in which ends the {@see StockMovementData} filled in.
     */
    public function recordMovement(StockMovementData $data): StockMovement
    {
        return ($this->recordStockMovement)($data);
    }

    // ── cost layers ─────────────────────────────────────────────────────────────────────

    /**
     * @return LengthAwarePaginator<int, StockBatch>
     */
    public function paginateStockBatches(StockBatchFilters $filters, int $perPage = 20): LengthAwarePaginator
    {
        return ($this->stockBatchListQuery)($filters, $perPage);
    }

    /**
     * Changes what a quantity of stock is carried at — the one write in this domain that moves
     * money without moving stock. See {@see RevalueStockBatch}.
     *
     * @throws BatchIsFullyConsumed
     * @throws RevaluationExceedsRemaining
     */
    public function revalueStockBatch(
        StockBatch $batch,
        string $unitCost,
        string $reason,
        int $userId,
        ?string $quantity = null,
    ): StockBatch {
        return ($this->revalueStockBatch)($batch, $unitCost, $reason, $userId, $quantity);
    }

    /**
     * What each draw of these movements took, and which cost layer it came from.
     *
     * The seam another context uses to attribute a movement's cost to whoever financed the
     * layers behind it. Inventory has no opinion about that — `investor_deal_id` is a column it
     * copies and never reads.
     *
     * @param  list<int>  $movementIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function consumptionBreakdownFor(array $movementIds): array
    {
        return ($this->consumptionBreakdown)($movementIds);
    }
}
