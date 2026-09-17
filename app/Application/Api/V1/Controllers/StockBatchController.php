<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Requests\Inventory\RevalueStockBatchRequest;
use App\Application\Api\V1\Resources\StockBatchResource;
use App\Application\Controller;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Queries\StockBatchFilters;
use App\Domain\Investor\InvestorService;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Stock cost layers
 *
 * What each quantity of stock on a shelf actually cost, and the one way to correct it.
 *
 * **These layers have existed since batch costing landed and nothing could read them.** A shelf's
 * balance was visible, the movements behind it were visible, and what any of it cost was not —
 * so a layer opened at zero, which is what every arrival with no recorded price and every unit of
 * stock predating costing does, was invisible until it turned up as an order with no material
 * cost at all.
 *
 * The list is ordered the way stock is consumed — oldest `received_at` first — because that is
 * the single most useful fact about it: the layer at the top is the one the next order draws
 * from.
 */
class StockBatchController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly InvestorService $investors,
    ) {}

    /**
     * Names the deal on every funded layer of a set — one query for the page, none for the rest.
     *
     * **Here rather than on the model.** Inventory does not import Investment, so a `belongsTo`
     * on `StockBatch` would be the dependency running the wrong way; the Application layer is
     * allowed to know both, and this is the only thing it has to know.
     *
     * @param  Collection<int, StockBatch>  $batches
     * @return Collection<int, StockBatch>
     */
    private function nameTheDeals($batches)
    {
        $summaries = $this->investors->dealSummaries(
            $batches->pluck('investor_deal_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
        );

        return $batches->each(function ($batch) use ($summaries): void {
            $summary = $batch->investor_deal_id === null
                ? null
                : ($summaries[(int) $batch->investor_deal_id] ?? null);

            $batch->setAttribute('investor_deal_code', $summary['code'] ?? null);
            $batch->setAttribute('investor_deal_investors', $summary['investors'] ?? null);
        });
    }

    /**
     * List cost layers
     *
     * Filter with `warehouse_id`, `stock_item_id`, and `uncosted`. **`uncosted=1` is the work
     * list**: every layer carrying no price with stock still on it, oldest first — which is to
     * say, in the order they will be drawn into orders at no material cost unless somebody
     * prices them.
     *
     * Used-up layers are excluded unless `remaining=0` asks for them: a layer with nothing left
     * cannot be repriced and is not part of any queue.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = StockBatchFilters::fromArray(
            $request->only(['warehouse_id', 'stock_item_id', 'uncosted', 'remaining']),
        );
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);

        $page = $this->inventory->paginateStockBatches($filters, $perPage);

        // **The paginator, not its collection.** `successWithPagination` reads the page's own
        // meta off the paginator; handing it a plain collection is how the list came back with
        // «الرد لا يحتوي على بيانات الصفحات». The enrichment mutates the models in place.
        $this->nameTheDeals($page->getCollection());

        return $this->successWithPagination(StockBatchResource::collection($page));
    }

    /**
     * Correct a cost layer's price
     *
     * Changes what a quantity of stock is carried at — all of the layer, or the part named by
     * `quantity`, which splits it.
     *
     * **Prospective, always.** Stock already drawn off this layer recorded its cost when it left,
     * and the orders it went into keep that figure. Correcting a layer that is half gone fixes
     * the half that is left; a layer with nothing left is refused outright.
     *
     * **A layer from a purchase order is corrected like any other.** The list says which ones
     * those are so the app can warn that the invoice may disagree, but refusing would exclude the
     * commonest correction there is.
     *
     * `reason` is required. This changes the books with no physical event behind it.
     */
    public function revalue(RevalueStockBatchRequest $request, StockBatch $stockBatch): JsonResponse
    {
        $batch = $this->inventory->revalueStockBatch(
            $stockBatch,
            (string) $request->validated('unit_cost'),
            (string) $request->validated('reason'),
            (int) $request->user()->id,
            $request->validated('quantity') !== null
                ? (string) $request->validated('quantity')
                : null,
        );

        return $this->success(
            new StockBatchResource($this->nameTheDeals(collect([
                $batch->load(['stockItem', 'warehouse', 'stockMovement']),
            ]))->first()),
            'تم تعديل تكلفة الدفعة بنجاح',
        );
    }
}
