<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Inventory\Models\StockItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockItem
 */
class StockItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Server-assigned, never settable — «S7». What staff say out loud.
            'code' => $this->code,

            'name' => $this->name,
            'width_cm' => $this->width_cm,
            'height_cm' => $this->height_cm,

            // The material this is a size of. Null for a standalone shelf. A grouped item's
            // `name` above is the material's — renaming the material renames every size of it.
            'stock_item_group_id' => $this->stock_item_group_id,
            'stock_item_group' => $this->whenLoaded(
                'stockItemGroup',
                fn (): ?array => $this->stockItemGroup === null ? null : [
                    'id' => $this->stockItemGroup->id,
                    'code' => $this->stockItemGroup->code,
                    'name' => $this->stockItemGroup->name,
                ],
            ),

            // Composed from the name and the size rather than stored, so renaming a material
            // renames every shelf of it at once. What a picker and a shortfall message show.
            'display_name' => $this->displayName(),

            // What this shelf is counted in. Independent of any product's `pricing_unit` — a
            // thing can be bought in by weight and sold by the piece — but shared by every
            // product that draws on it, which is exactly why it lives here and not on a product.
            'unit' => $this->unit->value,
            'unit_label' => $this->unit->label(),

            // **The last price recorded for this material — a suggestion for the form that
            // records an arrival, never a default the server applies.** Dated and sourced, so a
            // six-month-old figure looks six months old rather than reading as today's price.
            //
            // Present only where it was loaded, which is the single-item response and nothing
            // else: a listing carrying it would run a query per row for a hint no list shows.
            'last_known_unit_cost' => $this->whenLoaded(
                'latestCostedBatch',
                fn (): ?array => $this->latestCostedBatch === null ? null : [
                    'unit_cost' => (string) $this->latestCostedBatch->unit_cost,
                    'received_at' => $this->latestCostedBatch->received_at?->toIso8601String(),
                    'source_type_label' => $this->latestCostedBatch->source_type->label(),
                ],
            ),

            'description' => $this->description,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,

            // How many product sizes draw on this shelf — the number that makes the sharing
            // visible on a listing. Counted by the query, never by loading the variants.
            'variants_count' => $this->whenCounted('variants'),

            // The sizes themselves — only where they were loaded, which is `setVariants` and
            // nothing else. A listing that carried them would ship the whole catalogue inside the
            // inventory list to draw a number `variants_count` already answers.
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
