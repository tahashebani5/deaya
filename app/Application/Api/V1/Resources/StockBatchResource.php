<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Queries\StockBatchListQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One cost layer, as a screen needs to read it.
 *
 * **The three flags at the bottom are the whole reason this resource exists in the shape it
 * does.** A client deciding for itself whether a layer may be repriced would be keeping a second
 * copy of a rule the domain owns — and getting it wrong in the direction that offers a button
 * the server then refuses. So `can_be_revalued`, `is_partly_consumed` and `purchase_order_id`
 * travel with the layer, and the app draws exactly the warning each one earns.
 *
 * @mixin StockBatch
 */
class StockBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn (): array => [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ]),

            'stock_item_id' => $this->stock_item_id,
            'stock_item' => $this->whenLoaded('stockItem', fn (): array => [
                'id' => $this->stockItem->id,
                'code' => $this->stockItem->code,
                'name' => $this->stockItem->name,
                'display_name' => $this->stockItem->displayName(),
            ]),

            // Strings: money and quantities that are summed must reach the client exactly as
            // they were stored.
            'unit_cost' => (string) $this->unit_cost,
            'quantity_received' => (string) $this->quantity_received,
            'quantity_remaining' => (string) $this->quantity_remaining,
            'quantity_consumed' => $this->consumedQuantity(),

            'unit' => $this->unit->value,
            'unit_label' => $this->unit->label(),

            // **Whose money bought this layer.** Null on the ordinary layer the company paid for
            // itself, which is most of them. The code is attached by the controller rather than
            // read through a relation: Inventory does not import Investment, and a `belongsTo`
            // here would be the dependency running the wrong way.
            'investor_deal_id' => $this->investor_deal_id,
            'investor_deal_code' => $this->when(
                isset($this->investor_deal_code),
                fn (): ?string => $this->investor_deal_code,
            ),
            // Who is in that deal and what each put in — so «مَن يملك هذه البضاعة؟» is answered
            // on the shelf, without opening the deal to find out.
            'investor_deal_investors' => $this->when(
                isset($this->investor_deal_investors),
                fn (): ?array => $this->investor_deal_investors,
            ),

            'source_type' => $this->source_type->value,
            'source_type_label' => $this->source_type->label(),

            // **The FIFO key, and the reason this list is ordered the way it is.** Not
            // `created_at`: a layer relocated by a transfer keeps the age of the stock it
            // actually is, so goods do not get younger by moving shelves.
            'received_at' => $this->received_at?->toIso8601String(),

            // Null on every layer nobody has corrected — which is almost all of them.
            'revalued_at' => $this->revalued_at?->toIso8601String(),

            // Where this layer came from, and null for every batch opened before that column
            // existed. «غير معروف» is the honest reading of a null here, not an error.
            'stock_movement_id' => $this->stock_movement_id,
            'recorded_by' => $this->whenLoaded(
                'stockMovement',
                fn () => $this->stockMovement?->employee_id,
            ),

            // **The document behind the layer, read through the movement.** A purchase order
            // reached by two hops rather than stored twice: `stock_movements.reference_id` is
            // already the arrival that carries it.
            'purchase_order_id' => $this->purchaseOrderId(),

            // The layer this one was split off when part of a batch was repriced. Null on
            // everything else.
            'split_from_batch_id' => $this->split_from_batch_id,

            // **Three questions the app must not answer for itself.** Whether a layer may be
            // repriced at all, whether correcting it will only reach part of what arrived, and
            // whether an invoice somewhere says something different.
            // A layer with nothing left cannot be repriced, and neither can one an investor's
            // money bought: his share is paid on a cost agreed once, so a late invoice becomes a
            // deal expense instead. Both refusals live in `RevalueStockBatch`; this flag exists
            // so the app never offers a button whose only outcome is that refusal.
            'can_be_revalued' => ! $this->isFullyConsumed() && $this->investor_deal_id === null,
            'is_partly_consumed' => $this->isPartlyConsumed(),
            'is_uncosted' => $this->isUncosted(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The purchase order this layer's stock arrived against, if any.
     *
     * **Selected by the list query as one subquery for the whole page**, never looked up here —
     * it is two hops away and a per-row lookup is the N+1 that arrives one call site at a time.
     * See {@see StockBatchListQuery}.
     *
     * Absent on a single batch returned straight from a write, where there is one row and the
     * client already knows what it just edited. Null at every step is «not from a purchase
     * order», which is the only thing the app needs from this.
     */
    private function purchaseOrderId(): ?int
    {
        // `getAttributes()` rather than `getAttribute()`: strict mode throws on a column that
        // was never selected, and on a single batch returned from a write it never is.
        $id = $this->resource->getAttributes()['purchase_order_id'] ?? null;

        return $id === null ? null : (int) $id;
    }
}
