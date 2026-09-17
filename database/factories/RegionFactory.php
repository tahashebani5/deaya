<?php

namespace Database\Factories;

use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Region>
 */
class RegionFactory extends Factory
{
    /** @var class-string<Region> */
    protected $model = Region::class;

    /** (city_id, name) is unique, so names come from a counter rather than chance. */
    private static int $nameSequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'name' => 'منطقة '.(++self::$nameSequence),
            'code' => 's'.self::$nameSequence,
            'darb_branch' => 'فرع '.self::$nameSequence,
            'latitude' => null,
            'longitude' => null,
        ];
    }

    /** The shipping company has not published a zone or branch for this neighbourhood. */
    public function unrouted(): static
    {
        return $this->state(fn () => ['code' => null, 'darb_branch' => null]);
    }

    public function pinnedAt(float $latitude, float $longitude): static
    {
        return $this->state(fn () => ['latitude' => $latitude, 'longitude' => $longitude]);
    }
}
