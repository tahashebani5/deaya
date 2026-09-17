<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Enums\FulfilmentType;
use App\Domain\Delivery\Models\City;
use App\Domain\Order\Enums\DesignSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * The address fields are snapshots on a real order, so the factory fills them from the city
     * it makes rather than inventing text that disagrees with the foreign key.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'city_id' => City::factory(),
            'region_id' => null,
            'city_name' => fn (array $attributes) => City::find($attributes['city_id'])?->name ?? 'مدينة',
            'region_name' => null,
            'fulfilment_type' => FulfilmentType::Delivery,
            'status' => OrderStatus::New,
            'design_source' => DesignSource::None,
            'recipient_name' => null,
            'recipient_phone' => null,
            'address_details' => 'شارع الجمهورية، بجانب الصيدلية',
            'notes' => null,
            'items_total' => '0.00',
            'design_fee' => '0.00',
            'delivery_price' => '20.00',
            'discount' => '0.00',
            'additional_cost' => '0.00',
            'grand_total' => '20.00',
            'placed_at' => now(),
        ];
    }

    /** An order sitting in a given status, for testing what may follow it. */
    public function status(OrderStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /** Collected in person: the city is a branch and there is nothing to pay for delivery. */
    public function officePickup(): static
    {
        return $this->state(fn () => [
            'city_id' => City::factory()->officePickup(),
            'fulfilment_type' => FulfilmentType::OfficePickup,
            'delivery_price' => '0.00',
            'grand_total' => '0.00',
        ]);
    }

    /** Artwork we drew, which is the one case that may carry a design fee. */
    public function designedInHouse(): static
    {
        return $this->state(fn () => ['design_source' => DesignSource::InHouse]);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn () => ['customer_id' => $customer->getKey()]);
    }
}
