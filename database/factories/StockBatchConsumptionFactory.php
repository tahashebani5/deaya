<?php

namespace Database\Factories;

use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBatchConsumption;
use App\Domain\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockBatchConsumption>
 */
class StockBatchConsumptionFactory extends Factory
{
    /** @var class-string<StockBatchConsumption> */
    protected $model = StockBatchConsumption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_batch_id' => StockBatch::factory(),
            'stock_movement_id' => StockMovement::factory(),
            'quantity' => '10.000',
            'unit_cost' => '10.000',
            'total_cost' => '100.00',
        ];
    }
}
