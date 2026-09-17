<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Requests\Inventory\SetLowStockThresholdRequest;
use App\Application\Api\V1\Resources\WarehouseStockResource;
use App\Application\Api\V1\Resources\WarehouseStockSummaryResource;
use App\Application\Controller;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Queries\StockFilters;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Warehouse stock
 *
 * What is on the shelves of one warehouse, one line per size.
 *
 * **There is no endpoint here that sets a quantity, and that is the design.** A balance is a
 * running total of the ledger: it moved because something happened, and the something is
 * recorded at `/stock-movements`. A miscount is corrected with
 * `POST /stock-movements/adjustments`, which asks for the reason — a shelf count that changed
 * with nothing to explain it is exactly what this context exists to make impossible.
 *
 * The one field a person writes here is `low_stock_threshold`, because it is a preference rather
 * than a fact.
 *
 * Nested and scoped: a stock line has no life outside its warehouse, so `{stock}` resolves
 * *within* `{warehouse}` and another warehouse's line id is a 404 by construction.
 */
class WarehouseStockController extends Controller
{
    use ResponseTrait;

    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * List a warehouse's stock
     *
     * One row per size that has ever been here, in variant order. A size that arrived and has all
     * been used up stays as a line at `0.000` — its history is worth more than the tidiness of
     * removing it.
     *
     * Filter with `stock_item_id`, `in_stock`, and `low_stock` — the last of which finds the
     * lines that have fallen to the level someone asked to be warned at. A line with no threshold
     * set is outside that question and appears in neither `low_stock=true` nor `low_stock=false`.
     */
    public function index(Request $request, Warehouse $warehouse): JsonResponse
    {
        $filters = StockFilters::fromArray($request->only(['stock_item_id', 'low_stock', 'in_stock']));
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        return $this->successWithPagination(
            WarehouseStockResource::collection($this->inventory->paginateStocks($warehouse, $filters, $perPage)),
        );
    }

    /**
     * Summarise a warehouse's stock
     *
     * The whole warehouse in five numbers: how many sizes are on its shelves, how much there is
     * altogether, and how many lines are low, empty, or fine.
     *
     * **It takes no filters.** A summary that narrowed along with the list could not tell anyone
     * what they had narrowed from — «٤ من ٢٤» is the sentence, and the ٢٤ has to survive the
     * filter that produced the ٤.
     *
     * `low_stock_count` and `out_of_stock_count` overlap: a shelf at zero that somebody asked to
     * be warned about is in both. Each is defined to match exactly the list filter of the same
     * name, so a count shown on a button cannot disagree with the list that button opens.
     */
    public function summary(Warehouse $warehouse): JsonResponse
    {
        return $this->success(new WarehouseStockSummaryResource($this->inventory->summariseStocks($warehouse)));
    }

    /**
     * Set a low-stock alert
     *
     * The level at which this line should be flagged. Send `null` to stop warning about it
     * altogether — which is not the same as `0`, meaning "warn me when this reaches nothing".
     *
     * This is the only writable field on a balance line.
     */
    public function setThreshold(
        SetLowStockThresholdRequest $request,
        Warehouse $warehouse,
        WarehouseStock $stock,
    ): JsonResponse {
        $threshold = $request->input('low_stock_threshold');

        $updated = $this->inventory->setLowStockThreshold(
            $stock,
            $threshold !== null && $threshold !== '' ? number_format((float) $threshold, 3, '.', '') : null,
        );

        return $this->success(new WarehouseStockResource($updated), 'تم تحديث حد التنبيه بنجاح');
    }
}
