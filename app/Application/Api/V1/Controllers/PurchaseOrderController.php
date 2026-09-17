<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Controllers\Concerns\ReadsAuditTrail;
use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\PurchaseOrder\ChangePurchaseOrderStatusRequest;
use App\Application\Api\V1\Requests\PurchaseOrder\ReceivePurchaseOrderArrivalRequest;
use App\Application\Api\V1\Requests\PurchaseOrder\ReversePurchaseOrderReceiptRequest;
use App\Application\Api\V1\Requests\PurchaseOrder\StorePurchaseOrderRequest;
use App\Application\Api\V1\Requests\PurchaseOrder\UpdatePurchaseOrderRequest;
use App\Application\Api\V1\Resources\PurchaseOrderResource;
use App\Application\Api\V1\Resources\StockArrivalResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Investor\InvestorService;
use App\Domain\PurchaseOrder\DTOs\PurchaseOrderData;
use App\Domain\PurchaseOrder\DTOs\ReceivePurchaseOrderData;
use App\Domain\PurchaseOrder\DTOs\ReversePurchaseOrderReceiptData;
use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrder\Exceptions\PurchaseOrderTransitionNotAllowed;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\PurchaseOrder\PurchaseOrderService;
use App\Domain\PurchaseOrder\Queries\PurchaseOrderFilters;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Purchase orders
 *
 * Stock ordered from a vendor ahead of it arriving: `new → arrived → completed`, with
 * `cancelled` reachable from either open status — see `PurchaseOrderStatus`. Reading needs
 * `purchase_orders.view`; drafting, editing, sending and cancelling need
 * `purchase_orders.manage`. Receiving a shipment against one needs `inventory.manage` instead —
 * see {@see receiveArrival()} — the same guard `POST /stock-arrivals` already sits behind,
 * because posting a shipment is squarely part of that area regardless of which door it came in.
 *
 * No destroy route: a purchase order is paperwork with real history behind it the moment
 * anything ships against it, the same reasoning that leaves `StockArrival` without one.
 */
class PurchaseOrderController extends Controller
{
    use ReadsAuditTrail, ResponseTrait;

    public function __construct(
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly InvestorService $investors,
    ) {}

    /**
     * List purchase orders
     *
     * Newest first. Filter with `vendor_id`, `warehouse_id` and `status`, and narrow with
     * `search` — the vendor's name, the warehouse's name, or the order's own id.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = PurchaseOrderFilters::fromArray($request->only(['vendor_id', 'warehouse_id', 'status', 'search']));
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        return $this->successWithPagination(
            PurchaseOrderResource::collection($this->purchaseOrders->paginate($filters, $perPage)),
        );
    }

    /**
     * Purchase orders by status
     *
     * How many orders stand in each status, under the same `vendor_id`, `warehouse_id` and
     * `search` filters the list takes. What a supplier's screen draws «الجارية ٣ · المكتملة ٩» from —
     * one call rather than one per group, with the grouping done by whoever asked.
     *
     * Every status is present, zeros included: a missing key would leave the caller choosing
     * between a blank and a zero, and the two mean different things.
     *
     * `status` is accepted and ignored, so a screen may hand its whole filter over without
     * having to strip it: counts narrowed to the status already chosen would every one of them
     * equal the list's own length.
     */
    public function statusCounts(Request $request): JsonResponse
    {
        $filters = PurchaseOrderFilters::fromArray($request->only(['vendor_id', 'warehouse_id', 'search']));
        $counts = $this->purchaseOrders->statusCounts($filters);

        return $this->success(['counts' => $counts, 'total' => array_sum($counts)]);
    }

    /**
     * Create a purchase order
     *
     * Always drafted as `new`. Nothing is sent to the vendor and no stock moves until it is
     * marked as sent or a shipment is received against it.
     */
    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $order = $this->purchaseOrders->create(PurchaseOrderData::fromArray($request->validated()));

        return $this->created(new PurchaseOrderResource($order), 'تم إنشاء أمر الشراء بنجاح');
    }

    /**
     * Get one purchase order
     */
    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        // `stockArrivals` is what the reversal affordance is computed from — see
        // PurchaseOrderResource. Loaded here and not on the list: it is one query for one order,
        // and the list has no button to draw.
        $purchaseOrder->load(['vendor', 'warehouse', 'items.stockItem', 'additionalCosts', 'stockArrivals']);

        // Whose money is on this lorry — asked here because this is the screen where somebody is
        // about to receive it. Empty for the ordinary order the company paid for itself.
        $purchaseOrder->setAttribute(
            'investor_funding',
            $this->investors->fundingForPurchaseOrder((int) $purchaseOrder->getKey()),
        );

        // What a deal struck on this order would be born with, so the funding screen can show
        // the number instead of leaving it to be discovered afterwards.
        $purchaseOrder->setAttribute(
            'default_investor_profit_share_percent',
            $this->investors->defaultProfitSharePercent(),
        );

        return $this->success(new PurchaseOrderResource($purchaseOrder));
    }

    /**
     * Update a purchase order
     *
     * Only while it is still `new` — refused with 422 otherwise. Lines are replaced wholesale:
     * an item carrying an `id` updates that line, one without creates a new line, and any
     * existing line missing from the set is removed.
     */
    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $updated = $this->purchaseOrders->update($purchaseOrder, PurchaseOrderData::fromArray($request->validated()));

        return $this->success(new PurchaseOrderResource($updated), 'تم تحديث أمر الشراء بنجاح');
    }

    /**
     * Change a purchase order's status
     *
     * Manual moves only: `arrived` (marks it as sent to the vendor) or `cancelled`. `completed`
     * is never set this way — it is reached only once {@see PurchaseOrderService::receiveArrival()}
     * finds every line fully received.
     */
    public function changeStatus(ChangePurchaseOrderStatusRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $updated = match ($target = $request->status()) {
            PurchaseOrderStatus::Arrived => $this->purchaseOrders->send($purchaseOrder),
            PurchaseOrderStatus::Cancelled => $this->purchaseOrders->cancel($purchaseOrder),
            // Unreachable while ChangePurchaseOrderStatusRequest keeps restricting the input to
            // the two cases above — kept so a future third manual target fails loudly with a
            // readable message instead of an UnhandledMatchError leaking as a raw 500.
            default => throw PurchaseOrderTransitionNotAllowed::make($purchaseOrder->status, $target),
        };

        return $this->success(new PurchaseOrderResource($updated), 'تم تحديث حالة أمر الشراء بنجاح');
    }

    /**
     * Receive a shipment against a purchase order
     *
     * Posts a stock arrival the same way `POST /stock-arrivals` does — into the order's own
     * vendor and warehouse, which is why neither is part of this payload — and updates the
     * order's own lines and status from what came in. A line's total received quantity may never
     * exceed what was ordered, and the order moves to `completed` the moment every line is.
     *
     * `received_by` is stamped from the authenticated user, never read from the body, so a
     * receipt can never be attributed to a colleague.
     */
    public function receiveArrival(ReceivePurchaseOrderArrivalRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $arrival = $this->purchaseOrders->receiveArrival(
            $purchaseOrder,
            ReceivePurchaseOrderData::fromArray($request->validated(), (int) $request->user()->id),
        );

        return $this->created(new StockArrivalResource($arrival), 'تم تسجيل استلام الشحنة بنجاح');
    }

    /**
     * Undo a receipt entered in error
     *
     * Takes the shipment back off the shelf — the exact cost layers it opened, at their own
     * cost, never a FIFO draw on the oldest stock in the warehouse — rolls each line's
     * `quantity_received` back down, and reopens the order at `arrived` so it can be received
     * again correctly. The arrival document is kept and stamped as reversed, never deleted.
     *
     * **Allowed for 24 hours after the receipt was posted**, and refused outright — for
     * everybody, at any age — once any of the arriving stock has been drawn on or any of its
     * cost layers repriced by hand. Past those, the correction is a stocktake adjustment, which
     * writes the difference off instead of rewriting a receipt that demonstrably happened.
     *
     * Whoever holds `purchase_orders.reverse_receipt_any_time` may step past the 24 hours, and
     * past nothing else. `reason` is required of both, and `reversed_by` is stamped from the
     * authenticated user, never read from the body.
     */
    public function reverseReceipt(ReversePurchaseOrderReceiptRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $updated = $this->purchaseOrders->reverseReceipt(
            $purchaseOrder,
            ReversePurchaseOrderReceiptData::fromArray(
                $request->validated(),
                (int) $request->user()->id,
                // Read here rather than in the domain: who is asking is the boundary's business,
                // and what that permits is the Action's — see ReverseStockArrival.
                $request->user()->can(PermissionName::ReverseReceiptAnyTime->value),
            ),
        );

        $updated->load(['vendor', 'warehouse', 'items.stockItem', 'additionalCosts', 'stockArrivals']);

        return $this->success(new PurchaseOrderResource($updated), 'تم التراجع عن الاستلام بنجاح');
    }

    /**
     * A purchase order's history
     *
     * Every change to the order and its lines, newest first — who made it and what it was
     * before. Filter with `event`, `causer_id`, `from` and `to`.
     */
    public function logs(ActivityLogFilterRequest $request, PurchaseOrder $purchaseOrder, AuditService $audit): JsonResponse
    {
        return $this->auditTrailResponse($request, $purchaseOrder, $audit);
    }
}
