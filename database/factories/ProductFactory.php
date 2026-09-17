<?php

namespace Database\Factories;

use App\Domain\Catalog\Enums\PricingMode;
use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /** @var class-string<Product> */
    protected $model = Product::class;

    /** Slugs are unique in the database, so they come from a counter rather than chance. */
    private static int $slugSequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => 'product-'.(++self::$slugSequence),
            'name' => 'كيس '.fake()->word(),
            'description' => fake()->sentence(),
            'features' => ['مقاوم للماء', 'إمكانية طباعة الشعار'],
            // What the customer is charged by. What the *warehouse* counts a product's sizes in
            // lives on the stock item each size draws from, and the two are still allowed to
            // differ — see StockItemFactory::weighed().
            'pricing_unit' => PricingUnit::Piece,
            'pricing_mode' => PricingMode::Tiered,
            'min_order_quantity' => 100,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /** Plain bags sold by weight — the catalogue's «سادة» heading. */
    public function perKilogram(): static
    {
        return $this->state(fn () => [
            'pricing_unit' => PricingUnit::Kilogram,
            'min_order_quantity' => 5,
        ]);
    }

    /** Priced by hand, like the reinforced 3D paper bags. */
    public function quoteOnRequest(): static
    {
        return $this->state(fn () => [
            'pricing_mode' => PricingMode::QuoteOnRequest,
            'min_order_quantity' => 200,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
