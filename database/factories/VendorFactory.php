<?php

namespace Database\Factories;

use App\Domain\Vendor\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    /** @var class-string<Vendor> */
    protected $model = Vendor::class;

    /** Guarantees distinct phone numbers, the same way CustomerFactory does. */
    private static int $phoneSequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'contact_person' => fake()->name(),
            'phone' => '02'.str_pad((string) (++self::$phoneSequence), 8, '0', STR_PAD_LEFT),
            'email' => fake()->unique()->safeEmail(),
            'address' => 'طرابلس',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
