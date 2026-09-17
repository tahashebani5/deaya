<?php

namespace Database\Factories;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\Enums\StockBatchSourceType;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockBatch>
 */
class StockBatchFactory extends Factory
{
    /** @var class-string<StockBatch> */
    protected $model = StockBatch::class;

    /**
     * Writes every column directly, which nothing in `app/` may do — see the note on
     * {@see WarehouseStockFactory}. Tests about FIFO ordering itself build state through the API;
     * this is for arranging a layer a test needs to already exist.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'stock_item_id' => StockItem::factory(),
            'source_type' => StockBatchSourceType::PurchaseArrival,
            'stock_arrival_item_id' => null,
            'unit_cost' => '10.000',
            'quantity_received' => '100.000',
            'quantity_remaining' => '100.000',
            'unit' => PricingUnit::Piece,
            'received_at' => now(),
        ];
    }

    public function unitCost(string $unitCost): static
    {
        return $this->state(fn () => ['unit_cost' => $unitCost]);
    }

    public function remaining(string $quantityRemaining): static
    {
        return $this->state(fn () => ['quantity_remaining' => $quantityRemaining]);
    }

    public function receivedAt(\DateTimeInterface $receivedAt): static
    {
        return $this->state(fn () => ['received_at' => $receivedAt]);
    }

    public function source(StockBatchSourceType $sourceType): static
    {
        return $this->state(fn () => ['source_type' => $sourceType]);
    }
}
