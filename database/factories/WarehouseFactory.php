<?php

namespace Database\Factories;

use App\Domain\Inventory\Enums\WarehouseType;
use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    /** @var class-string<Warehouse> */
    protected $model = Warehouse::class;

    /** The name is unique, so generated ones come from a counter rather than chance. */
    private static int $nameSequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'مخزن '.(++self::$nameSequence),
            'type' => WarehouseType::Operational,
            'location' => 'طرابلس',
        ];
    }

    public function main(): static
    {
        return $this->state(fn () => ['type' => WarehouseType::Main]);
    }

    /** Nobody has written the address down yet. */
    public function unlocated(): static
    {
        return $this->state(fn () => ['location' => null]);
    }
}
