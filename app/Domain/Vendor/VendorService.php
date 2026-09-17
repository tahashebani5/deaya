<?php

declare(strict_types=1);

namespace App\Domain\Vendor;

use App\Domain\Customer\CustomerService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Vendor\Actions\CreateVendor;
use App\Domain\Vendor\Actions\RecordStockArrival;
use App\Domain\Vendor\Actions\ReverseStockArrival;
use App\Domain\Vendor\Actions\UpdateVendor;
use App\Domain\Vendor\DTOs\ReverseStockArrivalData;
use App\Domain\Vendor\DTOs\StockArrivalData;
use App\Domain\Vendor\DTOs\VendorData;
use App\Domain\Vendor\Models\StockArrival;
use App\Domain\Vendor\Models\Vendor;
use App\Domain\Vendor\Queries\StockArrivalFilters;
use App\Domain\Vendor\Queries\StockArrivalListQuery;
use App\Domain\Vendor\Queries\VendorFilters;
use App\Domain\Vendor\Queries\VendorListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The Vendor module's public front door.
 *
 * Other modules talk to vendors and their shipments *only* through this class — the same rule
 * {@see CustomerService} and {@see InventoryService}
 * follow. Posting a shipment ({@see RecordStockArrival()}) is this module's write into
 * Inventory: it depends on `InventoryService` (see {@see RecordStockArrival}), and the
 * dependency runs one way — Inventory never imports anything from here.
 */
class VendorService
{
    public function __construct(
        private readonly CreateVendor $createVendor,
        private readonly UpdateVendor $updateVendor,
        private readonly VendorListQuery $vendorListQuery,
        private readonly RecordStockArrival $recordStockArrival,
        private readonly ReverseStockArrival $reverseStockArrival,
        private readonly StockArrivalListQuery $stockArrivalListQuery,
    ) {}

    // ── vendors ─────────────────────────────────────────────────────────────────────────

    /**
     * @return LengthAwarePaginator<int, Vendor>
     */
    public function paginate(VendorFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return ($this->vendorListQuery)($filters, $perPage);
    }

    public function find(int $id): Vendor
    {
        return Vendor::query()->findOrFail($id);
    }

    public function create(VendorData $data): Vendor
    {
        return ($this->createVendor)($data);
    }

    public function update(Vendor $vendor, VendorData $data): Vendor
    {
        return ($this->updateVendor)($vendor, $data);
    }

    /**
     * Vendors are deactivated rather than deleted, so shipments already on record keep pointing
     * at a real row.
     */
    public function setActive(Vendor $vendor, bool $isActive): Vendor
    {
        $vendor->update(['is_active' => $isActive]);

        return $vendor;
    }

    // ── shipments ───────────────────────────────────────────────────────────────────────

    /**
     * @return LengthAwarePaginator<int, StockArrival>
     */
    public function paginateStockArrivals(StockArrivalFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return ($this->stockArrivalListQuery)($filters, $perPage);
    }

    public function findStockArrival(int $id): StockArrival
    {
        return StockArrival::query()
            ->with(['vendor', 'warehouse', 'receivedByUser', 'reversedByUser', 'items.stockItem', 'items.stockMovement'])
            ->findOrFail($id);
    }

    /**
     * Posts a shipment and applies it to the warehouse it landed in, atomically. See
     * {@see RecordStockArrival} for how each line reaches the stock ledger.
     */
    public function recordStockArrival(StockArrivalData $data): StockArrival
    {
        return ($this->recordStockArrival)($data);
    }

    /**
     * Takes a shipment entered in error back off the shelf and marks the document saying so,
     * atomically. See {@see ReverseStockArrival} for the four things it refuses and which single
     * one of them a manager's grant may step past.
     *
     * Reachable from `PurchaseOrder` as well as from this module's own screens: undoing a receipt
     * posted against an order is the same act on the same document, and the ordering paperwork
     * that has to be rolled back alongside it is that module's own business — see
     * `ReversePurchaseOrderReceipt`.
     */
    public function reverseStockArrival(StockArrival $arrival, ReverseStockArrivalData $data): StockArrival
    {
        return ($this->reverseStockArrival)($arrival, $data);
    }
}
