<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Inventory\Models\WarehouseStock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WarehouseStock
 */
class WarehouseStockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'stock_item_id' => $this->stock_item_id,

            // Strings, never numbers. A shelf count is compared against a ledger, and "1250.000"
            // survives a client's JSON parser intact where 1250.0 does not — the same reason
            // cities render their delivery price as a string.
            'quantity' => (string) $this->quantity,
            'low_stock_threshold' => $this->low_stock_threshold !== null
                ? (string) $this->low_stock_threshold
                : null,

            // Computed here rather than left to the client: "is this low" depends on a null
            // threshold meaning "nobody asked", which is exactly the sort of rule four different
            // clients would each get subtly wrong.
            'is_low_stock' => $this->isLowStock(),

            // A snapshot taken when this balance was first created — see ApplyStockChange::increase().
            'unit' => $this->unit->value,
            'unit_label' => $this->unit->label(),

            // What is on this shelf — a storekeeper reads «كيس شحن 25*35», not an id. Flattened
            // rather than nested because the item is only ever met through a balance line here,
            // and a client should not have to walk two levels for a label.
            //
            // **No product, deliberately.** A pile is not one product's: كيس شحن سادة and
            // كيس شحن مطبوع both draw on this row, so naming either of them here would be picking
            // one arbitrarily and telling the storekeeper the wrong thing. Which products use a
            // shelf is a question the stock item's own screen answers.
            'stock_item' => $this->whenLoaded('stockItem', fn (): array => [
                'id' => $this->stockItem->id,
                // The code, because it is what staff say out loud — «عندك S7؟» — and the one
                // thing on this row that is safe to read down a phone line.
                'code' => $this->stockItem->code,
                'name' => $this->stockItem->name,
                'width_cm' => $this->stockItem->width_cm,
                'height_cm' => $this->stockItem->height_cm,
                // Composed rather than stored, so renaming the material renames every shelf.
                'display_name' => $this->stockItem->displayName(),
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
