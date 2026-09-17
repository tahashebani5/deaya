<?php

namespace Database\Factories;

use App\Domain\Delivery\Enums\FulfilmentType;
use App\Domain\Delivery\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    /** @var class-string<City> */
    protected $model = City::class;

    /** Names are unique in the database, so they come from a counter rather than chance. */
    private static int $nameSequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'مدينة '.(++self::$nameSequence),
            'fulfilment_type' => FulfilmentType::Delivery,
            'is_region_required' => false,
            'delivery_price' => '20.00',
            'darb_branch' => 'فرع '.self::$nameSequence,
            // Left unset rather than faked: most cities genuinely have no pin, and a test that
            // wants one says so.
            'latitude' => null,
            'longitude' => null,
        ];
    }

    /** A city the customer must pick a neighbourhood inside — طرابلس, بنغازي, مصراتة … */
    public function requiringRegion(): static
    {
        return $this->state(fn () => ['is_region_required' => true]);
    }

    /** Collected in person: free, and no region to choose. */
    public function officePickup(): static
    {
        return $this->state(fn () => [
            'fulfilment_type' => FulfilmentType::OfficePickup,
            'is_region_required' => false,
            'delivery_price' => '0.00',
            'darb_branch' => null,
        ]);
    }

    /** Added to the map before the business agreed a rate for it. */
    public function withoutPrice(): static
    {
        return $this->state(fn () => ['delivery_price' => null]);
    }

    public function pinnedAt(float $latitude, float $longitude): static
    {
        return $this->state(fn () => ['latitude' => $latitude, 'longitude' => $longitude]);
    }
}
