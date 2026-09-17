<?php

namespace Database\Factories;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\Enums\StockBatchSourceType;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WarehouseStock>
 */
class WarehouseStockFactory extends Factory
{
    /** @var class-string<WarehouseStock> */
    protected $model = WarehouseStock::class;

    /**
     * Note this writes `quantity` directly, which nothing in `app/` may do.
     *
     * That is the point of a factory: a test arranging "a shelf with 500 on it" should say so in
     * one line rather than recording an arrival to get there. The tests that care whether the
     * ledger and the balance agree build their state through the API instead — see
     * `StockLedgerTest`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'stock_item_id' => StockItem::factory(),
            'quantity' => '100.000',
            'low_stock_threshold' => null,
            'unit' => PricingUnit::Piece,
        ];
    }

    /**
     * A factory-built balance has no arrival, adjustment or transfer behind it to source a cost
     * layer from — the same gap a real pre-existing balance had the day batch costing went live.
     * Treated exactly the same way: one zero-cost `OpeningBalance` batch, backdated so it is never
     * mistaken for the newest thing on the shelf, keeping `SUM(quantity_remaining)` equal to this
     * balance for any test that goes on to actually draw stock down (a transfer, a fulfillment, a
     * decreasing adjustment) rather than only reading it.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (WarehouseStock $stock): void {
            if (bccomp((string) $stock->quantity, '0', 3) <= 0) {
                return;
            }

            StockBatch::factory()->create([
                'warehouse_id' => $stock->warehouse_id,
                'stock_item_id' => $stock->stock_item_id,
                'source_type' => StockBatchSourceType::OpeningBalance,
                'unit_cost' => '0.000',
                'quantity_received' => $stock->quantity,
                'quantity_remaining' => $stock->quantity,
                'unit' => $stock->unit,
                'received_at' => '1970-01-01 00:00:00',
            ]);
        });
    }

    public function quantity(string $quantity): static
    {
        return $this->state(fn () => ['quantity' => $quantity]);
    }

    public function unit(PricingUnit $unit): static
    {
        return $this->state(fn () => ['unit' => $unit]);
    }

    /** A size that was here and has all been used up — the line stays, at zero. */
    public function empty(): static
    {
        return $this->state(fn () => ['quantity' => '0.000']);
    }

    public function warnAt(string $threshold): static
    {
        return $this->state(fn () => ['low_stock_threshold' => $threshold]);
    }
}
