<?php

namespace Database\Factories;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerShop;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerShop>
 */
class CustomerShopFactory extends Factory
{
    /** @var class-string<CustomerShop> */
    protected $model = CustomerShop::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => fake()->streetName().' — فرع',
            // Where the shop is. Required, so it is made rather than left to the caller — a
            // test that cares which city says so, and the rest get one that exists.
            'city_id' => City::factory(),
            // No neighbourhood by default: most cities on the map have none, and a test that
            // wants one says so. `inRegion()` keeps the pair consistent.
            'region_id' => null,
            // Bounded to roughly Libya, so factory data looks plausible on a map instead of
            // scattering shops across the planet. Nothing writes these through the API any
            // more; they are here for the tests that still assert on the columns.
            'latitude' => fake()->latitude(24, 33),
            'longitude' => fake()->longitude(9, 25),
            'page_url' => fake()->boolean(70) ? 'https://facebook.com/'.fake()->userName() : null,
        ];
    }

    /**
     * In a named neighbourhood — and in *its* city, not a second one.
     *
     * The pair has to agree: a shop in a region that belongs to another city is exactly what
     * validation refuses, so a factory must not be able to produce one either.
     */
    public function inRegion(Region $region): static
    {
        return $this->state(fn () => [
            'city_id' => $region->city_id,
            'region_id' => $region->getKey(),
        ]);
    }
}
