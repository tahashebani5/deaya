<?php

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Vendor\Models\StockArrival;
use App\Domain\Vendor\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockArrival>
 *
 * Builds the document only, with no lines and no ledger entries — useful for tests about reading
 * arrivals. Tests that care whether a posted shipment actually moved stock go through
 * `POST /stock-arrivals` instead, the same split `WarehouseStockFactory` documents for balances.
 */
class StockArrivalFactory extends Factory
{
    /** @var class-string<StockArrival> */
    protected $model = StockArrival::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'warehouse_id' => Warehouse::factory(),
            'received_by' => User::factory(),
            'invoice_number' => null,
            'notes' => null,
        ];
    }

    public function from(Vendor $vendor): static
    {
        return $this->state(fn () => ['vendor_id' => $vendor->id]);
    }

    public function into(Warehouse $warehouse): static
    {
        return $this->state(fn () => ['warehouse_id' => $warehouse->id]);
    }

    public function receivedBy(User $user): static
    {
        return $this->state(fn () => ['received_by' => $user->id]);
    }
}
