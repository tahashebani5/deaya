<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Requests\Inventory\RecordAdjustmentRequest;
use App\Application\Api\V1\Requests\Inventory\RecordArrivalRequest;
use App\Application\Api\V1\Requests\Inventory\RecordFulfillmentRequest;
use App\Application\Api\V1\Requests\Inventory\RecordTransferRequest;
use App\Application\Api\V1\Resources\StockMovementResource;
use App\Application\Controller;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Inventory\DTOs\StockMovementData;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Queries\MovementFilters;
use App\Domain\Investor\InvestorService;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Stock movements
 *
 * The stock ledger — every reason a quantity ever changed, and the only way to change one.
 * Recording a movement applies it to the balances it affects in the same transaction, so the
 * shelf and the reason for it can never disagree.
 *
 * Four endpoints rather than one with a `movement_type` field, because the four operations
 * genuinely take four different payloads: an arrival has no source, a fulfillment has no
 * destination, an adjustment has a direction instead of either. Each therefore documents exactly
 * the body it accepts.
 *
 * `quantity` is always positive. Direction lives in which end is filled in.
 *
 * Every one of them stamps `employee_id` from the authenticated user. It is never read from the
 * body, so a movement cannot be attributed to a colleague.
 */
class StockMovementController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly InvestorService $investors,
    ) {}

    /**
     * List movements
     *
     * The whole ledger, newest first. `warehouse_id` matches **either** end, so it answers
     * "everything that happened at the main store" — arrivals and despatches together.
     *
     * Filter with `warehouse_id`, `stock_item_id`, `movement_type`, `employee_id`,
     * `reference_id`, `from` and `to`. Dates are inclusive: `to=2026-08-03` includes that whole day.
     */
    /**
     * Says which deal each outgoing movement drew its goods from — and which of it was ours.
     *
     * **The split already existed and nobody could see it.** FIFO writes one consumption row per
     * cost layer it touches, each carrying that layer's deal, and an order taking a thousand bags
     * off two layers is two rows with two owners. Until now the feed showed «−1,000» and the
     * question «من مِن؟» had no answer on any screen.
     *
     * Three queries for the whole page, whatever its length: the consumption rows, then the deals
     * they name. A row per movement would have made a fifteen-line feed forty queries deep.
     *
     * @param  Collection<int, StockMovement>  $movements
     */
    private function nameTheDraws($movements): void
    {
        $breakdown = $this->inventory->consumptionBreakdownFor(
            $movements->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );

        if ($breakdown === []) {
            return;
        }

        $dealIds = [];

        foreach ($breakdown as $draws) {
            foreach ($draws as $draw) {
                if ($draw['investor_deal_id'] !== null) {
                    $dealIds[$draw['investor_deal_id']] = true;
                }
            }
        }

        $summaries = $this->investors->dealSummaries(array_map('intval', array_keys($dealIds)));

        foreach ($movements as $movement) {
            $draws = $breakdown[(int) $movement->getKey()] ?? [];

            if ($draws === []) {
                continue;
            }

            // Folded by owner: one line per deal, and one for the company's own stock, rather
            // than one per cost layer. Which layer it came off is the FIFO's business; whose
            // goods they were is the question being answered.
            $byOwner = [];

            foreach ($draws as $draw) {
                $key = $draw['investor_deal_id'] ?? 0;

                $byOwner[$key] ??= [
                    'investor_deal_id' => $draw['investor_deal_id'],
                    'code' => $draw['investor_deal_id'] === null
                        ? null
                        : ($summaries[$draw['investor_deal_id']]['code'] ?? null),
                    'quantity' => '0.000',
                    'total_cost' => '0.00',
                ];

                $byOwner[$key]['quantity'] = bcadd($byOwner[$key]['quantity'], $draw['quantity'], 3);
                $byOwner[$key]['total_cost'] = bcadd($byOwner[$key]['total_cost'], $draw['total_cost'], 2);
            }

            $movement->setAttribute('investor_draws', array_values($byOwner));
        }
    }

    public function index(Request $request): JsonResponse
    {
        $filters = MovementFilters::fromArray($request->only([
            'warehouse_id', 'stock_item_id', 'movement_type', 'adjustment_reason',
            'employee_id', 'reference_id', 'from', 'to',
        ]));
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        $page = $this->inventory->paginateMovements(
            $filters,
            $perPage,
            withCost: $request->user()?->can(PermissionName::ViewStockCost->value) === true,
        );

        $this->nameTheDraws($page->getCollection());

        return $this->successWithPagination(StockMovementResource::collection($page));
    }

    /**
     * Record an arrival
     *
     * Stock coming into the business from a supplier. Adds to the receiving warehouse's balance,
     * creating the line the first time a size arrives there.
     *
     * Refused with 422 for a fractional quantity of a per-piece product — the same rule that
     * stops an order for half a bag.
     */
    public function arrivals(RecordArrivalRequest $request): JsonResponse
    {
        $movement = $this->inventory->recordMovement(
            StockMovementData::arrival($request->validated(), (int) $request->user()->id),
        );

        return $this->created(new StockMovementResource($movement), 'تم تسجيل التوريد بنجاح');
    }

    /**
     * Record a transfer
     *
     * Stock moving between two of our own warehouses: the source is decremented and the
     * destination incremented, atomically.
     *
     * Refused with 422 when the source does not hold enough — the message carries both the
     * available and the requested quantity — or when both ends name the same warehouse.
     */
    public function transfers(RecordTransferRequest $request): JsonResponse
    {
        $movement = $this->inventory->recordMovement(
            StockMovementData::transfer($request->validated(), (int) $request->user()->id),
        );

        return $this->created(new StockMovementResource($movement), 'تم تسجيل التحويل بنجاح');
    }

    /**
     * Record a fulfillment
     *
     * Stock leaving for a customer's order. Decrements the despatching warehouse.
     *
     * `reference_id` is the order it went out on — nullable today because Orders has not landed;
     * it becomes required in the same change that adds the foreign key.
     */
    public function fulfillments(RecordFulfillmentRequest $request): JsonResponse
    {
        $movement = $this->inventory->recordMovement(
            StockMovementData::fulfillment($request->validated(), (int) $request->user()->id),
        );

        return $this->created(new StockMovementResource($movement), 'تم تسجيل الصرف بنجاح');
    }

    /**
     * Record a stocktake adjustment
     *
     * The shelf disagreed with the book. Names one warehouse and a `direction` — `increase` found
     * more than recorded, `decrease` found less.
     *
     * `notes` is required here and nowhere else: the other three movements explain themselves,
     * while an adjustment says the records were wrong and offers no reason of its own.
     *
     * A decrease that would take the balance below zero is refused. Stock cannot be negative, and
     * a count that says otherwise is a data error worth stopping rather than storing.
     */
    public function adjustments(RecordAdjustmentRequest $request): JsonResponse
    {
        $movement = $this->inventory->recordMovement(
            StockMovementData::adjustment($request->validated(), (int) $request->user()->id),
        );

        return $this->created(new StockMovementResource($movement), 'تم تسجيل التسوية بنجاح');
    }
}
