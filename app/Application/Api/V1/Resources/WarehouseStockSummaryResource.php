<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Inventory\DTOs\StockSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The numbers at the top of one warehouse's balance screen.
 *
 * Not a model resource — there is no summary row anywhere, and inventing a table to be counted
 * from another table is not a design. What it wraps is a {@see StockSummary} the database
 * computed in one pass.
 *
 * @property StockSummary $resource
 */
class WarehouseStockSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = $this->resource;

        return [
            'total_lines' => $summary->totalLines,

            // A string, like every other quantity this API sends: it is a sum of decimals and
            // survives a client's JSON parser intact only as text.
            'total_quantity' => $summary->totalQuantity,

            // These two overlap — an empty shelf with a threshold is both — because each matches
            // exactly the filter its button opens. The screen's bar makes them exclusive by
            // taking `healthy_count` as the leftover rather than subtracting them from the total.
            'low_stock_count' => $summary->lowStockCount,
            'out_of_stock_count' => $summary->outOfStockCount,
            'healthy_count' => $summary->healthyCount,
        ];
    }
}
