<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Controllers\Concerns\ReadsAuditTrail;
use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\Vendor\StoreStockArrivalRequest;
use App\Application\Api\V1\Resources\StockArrivalResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Domain\Vendor\DTOs\StockArrivalData;
use App\Domain\Vendor\Models\StockArrival;
use App\Domain\Vendor\Queries\StockArrivalFilters;
use App\Domain\Vendor\VendorService;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stock arrivals
 *
 * Shipments received from vendors — one document, one warehouse, one or more lines. Posting one
 * applies every line to the receiving warehouse's balance in the same transaction, through
 * `InventoryService::recordMovement()`: the same single write path every other way stock enters
 * or leaves a warehouse already goes through.
 *
 * Guarded by the same `inventory.view`/`inventory.manage` pair as warehouses, balances and the
 * stock ledger — a shipment is squarely part of that area.
 *
 * No update or destroy route: a posted arrival is never edited, the same rule
 * `stock_movements` itself follows.
 */
class StockArrivalController extends Controller
{
    use ReadsAuditTrail, ResponseTrait;

    public function __construct(private readonly VendorService $vendors) {}

    /**
     * List stock arrivals
     *
     * Newest first. Filter with `vendor_id`, `warehouse_id`, `from` and `to`. Dates are
     * inclusive: `to=2026-08-06` includes that whole day.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = StockArrivalFilters::fromArray($request->only(['vendor_id', 'warehouse_id', 'from', 'to']));
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        return $this->successWithPagination(
            StockArrivalResource::collection($this->vendors->paginateStockArrivals($filters, $perPage)),
        );
    }

    /**
     * Record a stock arrival
     *
     * Stock coming into the business from a vendor. Every line adds to the receiving warehouse's
     * balance, creating the line the first time a size arrives there.
     *
     * `received_by` is stamped from the authenticated user, never read from the body, so a
     * receipt can never be attributed to a colleague.
     *
     * Refused with 422 for a fractional quantity of a per-piece product on any line — the same
     * rule that stops a single-line arrival for half a bag.
     */
    public function store(StoreStockArrivalRequest $request): JsonResponse
    {
        $arrival = $this->vendors->recordStockArrival(
            StockArrivalData::fromArray($request->validated(), (int) $request->user()->id),
        );

        return $this->created(new StockArrivalResource($arrival), 'تم تسجيل التوريد بنجاح');
    }

    /**
     * Get one stock arrival
     */
    public function show(StockArrival $stockArrival): JsonResponse
    {
        return $this->success(new StockArrivalResource(
            $stockArrival->load(['vendor', 'warehouse', 'receivedByUser', 'items.stockItem']),
        ));
    }

    /**
     * A stock arrival's history
     *
     * Every change to the document and its lines, newest first — who made it and what it was
     * before. In practice this is just the moment it was created: nothing ever updates one.
     *
     * Filter with `event`, `causer_id`, `from` and `to`.
     */
    public function logs(ActivityLogFilterRequest $request, StockArrival $stockArrival, AuditService $audit): JsonResponse
    {
        return $this->auditTrailResponse($request, $stockArrival, $audit);
    }
}
