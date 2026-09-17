<?php

namespace Database\Factories;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockItemGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockItem>
 */
class StockItemFactory extends Factory
{
    /** @var class-string<StockItem> */
    protected $model = StockItem::class;

    /**
     * Sequence-generated, not random: `(name, width_cm, height_cm)` carries a unique index, and a
     * chance collision failing an unrelated test is a miserable bug to find. RULES.md §6.
     */
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $width = 20 + (++self::$sequence % 40);
        $height = $width + 10;

        return [
            'name' => 'كيس شحن',
            'width_cm' => $width,
            'height_cm' => $height,
            'unit' => PricingUnit::Piece,
            'description' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function named(string $name): static
    {
        return $this->state(fn () => ['name' => $name]);
    }

    public function size(int $width, int $height): static
    {
        return $this->state(fn () => ['width_cm' => $width, 'height_cm' => $height]);
    }

    /** A shelf with no dimensions — a roll, an ink, anything counted without a size. */
    public function unsized(): static
    {
        return $this->state(fn () => ['width_cm' => null, 'height_cm' => null]);
    }

    public function unit(PricingUnit $unit): static
    {
        return $this->state(fn () => ['unit' => $unit]);
    }

    /** Weighed rather than counted, so fractional movements off it are legal. */
    public function weighed(): static
    {
        return $this->state(fn () => ['unit' => PricingUnit::Kilogram]);
    }

    /**
     * Filed under a material, taking its name — which is what a grouped item always does.
     *
     * The factory leaves items ungrouped by default: a standalone shelf is a real thing, and a
     * test that is not about materials should not have to invent one.
     */
    public function inGroup(StockItemGroup $group): static
    {
        return $this->state(fn () => [
            'stock_item_group_id' => $group->getKey(),
            'name' => $group->name,
        ]);
    }
}
