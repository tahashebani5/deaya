<?php

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\ManufacturingCostType;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\ProductionCostEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionCostEntry>
 */
class ProductionCostEntryFactory extends Factory
{
    /** @var class-string<ProductionCostEntry> */
    protected $model = ProductionCostEntry::class;

    /**
     * Building an entry this way does not move `order_items.labor_cost`/`overhead_cost`, which
     * the real write path would never do — see the note on {@see OrderPaymentFactory}. Right for
     * tests about reading the ledger, wrong for tests about the cached totals; those go through
     * the actions.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'order_item_id' => null,
            'cost_type' => ManufacturingCostType::Labor,
            'quantity' => '10.000',
            'rate' => '5.000',
            'amount' => '50.00',
            'recorded_by' => User::factory(),
            'incurred_at' => now(),
            'reverses_entry_id' => null,
            'notes' => null,
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn () => ['order_id' => $order->getKey()]);
    }

    public function forItem(OrderItem $item): static
    {
        return $this->state(fn () => [
            'order_id' => $item->order_id,
            'order_item_id' => $item->getKey(),
        ]);
    }

    public function type(ManufacturingCostType $type): static
    {
        return $this->state(fn () => ['cost_type' => $type]);
    }

    public function amount(string $amount): static
    {
        return $this->state(fn () => ['amount' => $amount]);
    }

    /** A scrap-loss entry: FIFO-derived, so it carries no rate or quantity of its own. */
    public function scrapLoss(): static
    {
        return $this->state(fn () => [
            'cost_type' => ManufacturingCostType::ScrapLoss,
            'quantity' => null,
            'rate' => null,
        ]);
    }

    public function reversing(ProductionCostEntry $entry): static
    {
        return $this->state(fn () => [
            'order_id' => $entry->order_id,
            'order_item_id' => $entry->order_item_id,
            'cost_type' => $entry->cost_type,
            'quantity' => $entry->quantity,
            'rate' => $entry->rate,
            'amount' => $entry->amount,
            'reverses_entry_id' => $entry->getKey(),
        ]);
    }
}
