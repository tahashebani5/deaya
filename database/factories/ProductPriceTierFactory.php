<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductPriceTier>
 */
class ProductPriceTierFactory extends Factory
{
    /** @var class-string<ProductPriceTier> */
    protected $model = ProductPriceTier::class;

    /**
     * A variant cannot have two tiers starting at the same quantity, so generated tiers walk
     * the usual catalogue breaks instead of all defaulting to 1 — otherwise `count(3)` would
     * violate the unique index.
     */
    private const BREAKS = [1, 300, 1000, 2500, 5000];

    private static int $breakSequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $minQuantity = self::BREAKS[self::$breakSequence++ % count(self::BREAKS)];

        return [
            'product_variant_id' => ProductVariant::factory(),
            'min_quantity' => $minQuantity,
            'unit_price' => '1.000',
        ];
    }

    public function from(float|int|string $minQuantity, string $unitPrice): static
    {
        return $this->state(fn () => [
            'min_quantity' => $minQuantity,
            'unit_price' => $unitPrice,
        ]);
    }
}
