<?php

namespace Database\Factories;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\Vendor\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 *
 * Builds the document only, with no lines — useful for tests about reading and listing orders.
 * Tests that care whether sending or receiving actually changed something go through the real
 * endpoints instead, the same split `StockArrivalFactory` documents for shipments.
 */
class PurchaseOrderFactory extends Factory
{
    /** @var class-string<PurchaseOrder> */
    protected $model = PurchaseOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'warehouse_id' => Warehouse::factory(),
            'status' => PurchaseOrderStatus::New,
            'order_date' => now()->toDateString(),
            'expected_date' => null,
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

    public function arrived(): static
    {
        return $this->state(fn () => ['status' => PurchaseOrderStatus::Arrived]);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => PurchaseOrderStatus::Completed]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => PurchaseOrderStatus::Cancelled]);
    }
}
