<?php

namespace Database\Factories;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\PurchaseOrder\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrderItem>
 */
class PurchaseOrderItemFactory extends Factory
{
    /** @var class-string<PurchaseOrderItem> */
    protected $model = PurchaseOrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'stock_item_id' => StockItem::factory(),
            'quantity_ordered' => '10.000',
            'quantity_received' => '0.000',
            'base_total_cost' => '50.00',
            'base_unit_cost' => '5.000',
            'allocated_additional_cost' => '0.00',
            'final_unit_cost' => '5.000',
            'final_total_cost' => '50.00',
            'unit' => PricingUnit::Piece,
        ];
    }

    // Deliberately not named `for()` — that overrides Eloquent's own
    // `Factory::for($factory, $relationship)` with an incompatible signature, the same trap
    // StockArrivalFactory's `from()`/`into()` naming avoids.
    public function forOrder(PurchaseOrder $order): static
    {
        return $this->state(fn () => ['purchase_order_id' => $order->id]);
    }

    public function ofStockItem(StockItem $item): static
    {
        return $this->state(fn () => ['stock_item_id' => $item->id]);
    }
}
