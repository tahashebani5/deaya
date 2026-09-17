<?php

namespace Database\Factories;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Shortage\Enums\ShortageSource;
use App\Domain\Shortage\Enums\ShortageStatus;
use App\Domain\Shortage\Models\Shortage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shortage>
 */
class ShortageFactory extends Factory
{
    /** @var class-string<Shortage> */
    protected $model = Shortage::class;

    /**
     * A manual shortage, because that is the one a test can make on its own.
     *
     * An order-born one is not a state this factory offers as a default: it is produced by the
     * reconciliation from a real line, and a factory that could fabricate one would let a test
     * assert against a row the sync would never have written.
     *
     * `code` is left out — the model allocates it on `creating`, the same as an order's.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => ShortageSource::Manual,
            'name' => 'كيس شحن ٢٥*٣٥',
            'unit' => PricingUnit::Kilogram,
            'required_quantity' => '30.000',
            'supplied_quantity' => '0.000',
            'total_paid' => '0.00',
            'status' => ShortageStatus::New,
        ];
    }

    /**
     * The shape the reconciliation writes: linked to a real line, with the order and customer
     * copied off it.
     *
     * Takes the line rather than making one, because the point of an order-born shortage is that
     * its numbers agree with a line that exists — a factory-made line nobody set `shortage_quantity`
     * on would produce a row the next sync immediately deletes.
     */
    public function fromOrderItem(OrderItem $item): static
    {
        return $this->state(function (array $attributes) use ($item): array {
            $order = $item->order ?? Order::query()->findOrFail($item->order_id);

            return [
                'source' => ShortageSource::FromOrder,
                'order_id' => $order->getKey(),
                'order_item_id' => $item->getKey(),
                'customer_id' => $order->customer_id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'name' => trim($item->product_name.' — '.$item->variant_label),
                'unit' => $item->pricing_unit,
                'required_quantity' => (string) ($item->shortage_quantity ?? '0.000'),
            ];
        });
    }

    public function assignedTo(int $userId): static
    {
        return $this->state(fn (array $attributes): array => [
            'assigned_to_user_id' => $userId,
            'status' => ShortageStatus::Searching,
        ]);
    }
}
