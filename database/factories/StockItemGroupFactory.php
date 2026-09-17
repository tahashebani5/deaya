<?php

namespace Database\Factories;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\Models\StockItemGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockItemGroup>
 */
class StockItemGroupFactory extends Factory
{
    /** @var class-string<StockItemGroup> */
    protected $model = StockItemGroup::class;

    /**
     * Sequence-generated, not random: `name` carries a unique index, and a chance collision
     * failing an unrelated test is a miserable bug to find. RULES.md §6.
     */
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'مادة '.(++self::$sequence),
            'default_unit' => PricingUnit::Piece,
            'description' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function named(string $name): static
    {
        return $this->state(fn () => ['name' => $name]);
    }

    /** Weighed rather than counted, so the sizes created under it take kilograms. */
    public function weighed(): static
    {
        return $this->state(fn () => ['default_unit' => PricingUnit::Kilogram]);
    }
}
