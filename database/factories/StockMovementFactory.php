<?php

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /** @var class-string<StockMovement> */
    protected $model = StockMovement::class;

    /**
     * An arrival by default — the only movement that needs no stock to already exist.
     *
     * Building a movement this way writes a ledger row **without** touching any balance, which
     * `RecordStockMovement` would never do. That is fine for tests about reading the ledger, and
     * wrong for tests about balances; those go through the endpoints.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_item_id' => StockItem::factory(),
            'from_warehouse_id' => null,
            'to_warehouse_id' => Warehouse::factory(),
            'quantity' => '50.000',
            'movement_type' => MovementType::PurchaseArrival,
            'reference_id' => null,
            'employee_id' => User::factory(),
            'notes' => null,
        ];
    }

    public function arrival(Warehouse $to): static
    {
        return $this->state(fn () => [
            'movement_type' => MovementType::PurchaseArrival,
            'from_warehouse_id' => null,
            'to_warehouse_id' => $to->id,
        ]);
    }

    public function transfer(Warehouse $from, Warehouse $to): static
    {
        return $this->state(fn () => [
            'movement_type' => MovementType::InternalTransfer,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
        ]);
    }

    public function fulfillment(Warehouse $from): static
    {
        return $this->state(fn () => [
            'movement_type' => MovementType::OrderFulfillment,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => null,
        ]);
    }

    /** A stocktake correction. `$found` true = the shelf held more than the book said. */
    public function adjustment(Warehouse $warehouse, bool $found = true): static
    {
        return $this->state(fn () => [
            'movement_type' => MovementType::Adjustment,
            'from_warehouse_id' => $found ? null : $warehouse->id,
            'to_warehouse_id' => $found ? $warehouse->id : null,
            'notes' => 'جرد',
        ]);
    }

    public function quantity(string $quantity): static
    {
        return $this->state(fn () => ['quantity' => $quantity]);
    }

    public function by(User $employee): static
    {
        return $this->state(fn () => ['employee_id' => $employee->id]);
    }

    public function forStockItem(StockItem $item): static
    {
        return $this->state(fn () => ['stock_item_id' => $item->id]);
    }
}
