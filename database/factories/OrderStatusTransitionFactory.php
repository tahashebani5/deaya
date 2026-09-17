<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderStatusTransition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderStatusTransition>
 */
class OrderStatusTransitionFactory extends Factory
{
    protected $model = OrderStatusTransition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'from_status' => OrderStatus::New,
            'to_status' => OrderStatus::Printing,
            'reason' => null,
            'user_id' => null,
        ];
    }
}
