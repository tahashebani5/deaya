<?php

namespace Database\Factories;

use App\Domain\Customer\Models\BusinessField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessField>
 */
class BusinessFieldFactory extends Factory
{
    /** @var class-string<BusinessField> */
    protected $model = BusinessField::class;

    /** Names are unique in the database, so they come from a counter rather than chance. */
    private static int $nameSequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'مجال '.(++self::$nameSequence),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /** No longer offered when recording a shop. */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function named(string $name): static
    {
        return $this->state(fn () => ['name' => $name]);
    }
}
