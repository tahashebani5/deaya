<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Order\Enums\ManufacturingCostType;
use App\Domain\Order\Models\ManufacturingCostRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManufacturingCostRate>
 */
class ManufacturingCostRateFactory extends Factory
{
    /** @var class-string<ManufacturingCostRate> */
    protected $model = ManufacturingCostRate::class;

    /**
     * A product-specific rate by default — the default/fallback shape is opted into via
     * {@see default()}, since a factory-built product would otherwise make every rate look
     * product-specific whether the test asked for that or not.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'cost_type' => ManufacturingCostType::Labor,
            'rate_per_unit' => '5.000',
            'is_active' => true,
            'notes' => null,
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn () => ['product_id' => $product->getKey(), 'product_variant_id' => null]);
    }

    /** Specific to one size — outranks a product-wide or default rate for the same cost type. */
    public function forVariant(ProductVariant $variant): static
    {
        return $this->state(fn () => ['product_id' => null, 'product_variant_id' => $variant->getKey()]);
    }

    /** The fallback rate for a cost type — applies when neither tier above has a row. */
    public function default(): static
    {
        return $this->state(fn () => ['product_id' => null, 'product_variant_id' => null]);
    }

    public function type(ManufacturingCostType $type): static
    {
        return $this->state(fn () => ['cost_type' => $type]);
    }

    public function rate(string $ratePerUnit): static
    {
        return $this->state(fn () => ['rate_per_unit' => $ratePerUnit]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
