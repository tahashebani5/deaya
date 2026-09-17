<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Enums\ProductionMode;
use App\Domain\Catalog\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    protected $model = ProductCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Unique per row: the partial index refuses two live categories with one name, and
            // a fixed value here would fail the second `create()` in any test making two.
            'name' => 'تصنيف '.$this->faker->unique()->numberBetween(1, 100_000),
            'description' => null,
            'is_active' => true,
            'sort_order' => 0,
            // The road every order took before flows existed, which is what an unremarkable
            // category should still mean.
            'production_mode' => ProductionMode::InHouse,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Goods that are already made — the catalogue's «سادة».
     *
     * An order made only of these walks جديدة → جاهزة; see `ResolveOrderFlow`.
     */
    public function skipsProduction(): static
    {
        return $this->state(fn () => ['production_mode' => ProductionMode::None]);
    }

    /**
     * «وسيط» — goods an outside vendor makes for us.
     *
     * An order made only of these walks جديدة → (قيد التصميم) → قيد التصنيع → جاهزة, deducts no
     * stock, and owes a vendor; see OUTSOURCED-PRODUCTS.md.
     */
    public function outsourced(): static
    {
        return $this->state(fn () => ['production_mode' => ProductionMode::Outsourced]);
    }

    /**
     * A heading a deal may be opened against — see `ProductCategory::isInvestable()`.
     *
     * The default is null, not false, and the two are different answers: null asks the parent
     * heading, false refuses on this row's own account.
     */
    public function investable(): static
    {
        return $this->state(fn () => ['is_investable' => true]);
    }
}
