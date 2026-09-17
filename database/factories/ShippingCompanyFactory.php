<?php

namespace Database\Factories;

use App\Domain\Delivery\Models\ShippingCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingCompany>
 */
class ShippingCompanyFactory extends Factory
{
    /** @var class-string<ShippingCompany> */
    protected $model = ShippingCompany::class;

    /** Names are unique in the database, so they come from a counter rather than chance. */
    private static int $nameSequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'شركة توصيل '.(++self::$nameSequence),
            'phone' => '091'.str_pad((string) self::$nameSequence, 7, '0', STR_PAD_LEFT),
            'notes' => null,
            'is_active' => true,
            'is_default' => false,
        ];
    }

    /** One we no longer deal with: still named by old orders, never offered on a new one. */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * The one a dispatch opens on.
     *
     * At most one live company may be this — the database enforces it — so a test making a
     * second default is a test that fails loudly rather than one that quietly has two.
     */
    public function asDefault(): static
    {
        return $this->state(fn () => ['is_active' => true, 'is_default' => true]);
    }
}
