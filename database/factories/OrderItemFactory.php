<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $product = Product::factory();

        return [
            'order_id' => Order::factory(),
            'product_id' => $product,
            'product_variant_id' => fn (array $attributes) => ProductVariant::factory()->create([
                'product_id' => $attributes['product_id'],
            ])->getKey(),
            'product_name' => 'كيس شحن',
            'variant_label' => '25*35',
            'pricing_unit' => PricingUnit::Piece,
            'quantity' => '300.000',
            'unit_price' => '1.100',
            'line_total' => '330.00',
            'notes' => null,
            'sort_order' => 0,
        ];
    }
}
