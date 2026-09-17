<?php

namespace Database\Factories;

use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\PurchaseOrder\Models\PurchaseOrderAdditionalCost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrderAdditionalCost>
 */
class PurchaseOrderAdditionalCostFactory extends Factory
{
    /** @var class-string<PurchaseOrderAdditionalCost> */
    protected $model = PurchaseOrderAdditionalCost::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'name' => 'Delivery',
            'amount' => '20.00',
        ];
    }

    // Deliberately not named `for()` — see PurchaseOrderItemFactory::forOrder() for why.
    public function forOrder(PurchaseOrder $order): static
    {
        return $this->state(fn () => ['purchase_order_id' => $order->id]);
    }
}
