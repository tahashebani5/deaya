<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Carrier\Models\NawrisParcel;
use App\Domain\Carrier\Models\NawrisParcelOrder;
use App\Domain\Order\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NawrisParcelOrder>
 */
class NawrisParcelOrderFactory extends Factory
{
    protected $model = NawrisParcelOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nawris_parcel_id' => NawrisParcel::factory(),
            'order_id' => Order::factory(),
            'amount_to_collect' => '100.00',
        ];
    }
}
