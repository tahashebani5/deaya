<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One order on a deal's list — what it took off the deal's shelf and what it gave back.
 *
 * **`profit` and `investors_share` are two different figures, published side by side.** The first
 * is what the deal earned on the order; the second is what the ledger actually paid the people in
 * it. Their difference is the company's cut, and a screen that showed one of them alone would
 * make a person believe it was the other.
 *
 * Backed by an array rather than a model — the row is walked out of the draw ledger and the money
 * is arithmetic over it, so there was never a row to load.
 *
 * @property array<string, mixed> $resource
 */
class DealOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'order_id' => $row['order_id'],
            'code' => $row['code'],

            'status' => $row['status'],
            'status_label' => $row['status_label'],

            'customer_name' => $row['customer_name'],

            // Delivered, or placed for one still on the road — see the query.
            'occurred_at' => $row['occurred_at'] === null
                ? null
                : Carbon::parse($row['occurred_at'])->toIso8601String(),

            // The order's whole money, so the deal's slice of it can be read against something.
            'grand_total' => $row['grand_total'],

            'quantity' => $row['quantity'],
            'material_cost' => $row['material_cost'],
            'revenue' => $row['revenue'],
            'conversion_cost' => $row['conversion_cost'],

            'profit' => $row['profit'],

            // Null while the ledger holds no row for this order — every order before
            // «تم الاستلام», and the delivered one whose share rounded to nothing. Zero
            // would say a different thing entirely: an order that broke exactly even.
            'investors_share' => $row['investors_share'],
            'company_share' => $row['company_share'],
            'is_posted' => $row['is_posted'],
        ];
    }
}
