<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The delivery map: a city, the regions inside it, and the guarantees the database itself makes.
 *
 * Arrange - Act - Assert throughout.
 */
class CityAndRegionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_city_stores_its_delivery_details(): void
    {
        // Act
        $city = City::create([
            'name' => 'طرابلس',
            'is_region_required' => true,
            'delivery_price' => '15.00',
            'darb_branch' => 'زناتة، طرابلس',
        ]);

        // Assert
        $this->assertDatabaseHas('cities', [
            'name' => 'طرابلس',
            'is_region_required' => true,
            'delivery_price' => '15.00',
            'darb_branch' => 'زناتة، طرابلس',
        ]);
        $this->assertTrue($city->fresh()->is_region_required);
    }

    public function test_coordinates_are_optional_on_a_city(): void
    {
        // Act
        $city = City::factory()->create();

        // Assert
        $this->assertNull($city->latitude);
        $this->assertNull($city->longitude);
    }

    public function test_coordinates_are_optional_on_a_region(): void
    {
        // Act
        $region = Region::factory()->create();

        // Assert
        $this->assertNull($region->latitude);
        $this->assertNull($region->longitude);
    }

    public function test_a_city_can_be_pinned_on_the_map(): void
    {
        // Act
        $city = City::factory()->create(['latitude' => 32.8872000, 'longitude' => 13.1913000]);

        // Assert — numbers, not the "32.8872000" a decimal column returns by default
        $stored = $city->fresh();
        $this->assertSame(32.8872, $stored->latitude);
        $this->assertSame(13.1913, $stored->longitude);
    }

    public function test_a_region_can_be_pinned_on_the_map(): void
    {
        // Act
        $region = Region::factory()->create(['latitude' => 32.3754000, 'longitude' => 15.0925000]);

        // Assert
        $stored = $region->fresh();
        $this->assertSame(32.3754, $stored->latitude);
        $this->assertSame(15.0925, $stored->longitude);
    }

    public function test_a_city_without_a_delivery_price_yet_is_allowed(): void
    {
        // Act
        $city = City::factory()->create(['delivery_price' => null]);

        // Assert
        $this->assertNull($city->fresh()->delivery_price);
    }

    public function test_delivery_price_keeps_its_two_decimal_places_exactly(): void
    {
        // Act
        $city = City::factory()->create(['delivery_price' => '20.5']);

        // Assert — a string, never a float: money that is summed must not drift
        $this->assertSame('20.50', $city->fresh()->delivery_price);
    }

    public function test_two_cities_cannot_share_a_name(): void
    {
        // Arrange
        City::factory()->create(['name' => 'مصراتة']);

        // Assert
        $this->expectException(QueryException::class);

        // Act
        City::factory()->create(['name' => 'مصراتة']);
    }

    public function test_one_city_cannot_list_the_same_region_twice(): void
    {
        // Arrange
        $city = City::factory()->create();
        Region::factory()->create(['city_id' => $city->id, 'name' => 'قرية ناصر']);

        // Assert
        $this->expectException(QueryException::class);

        // Act
        Region::factory()->create(['city_id' => $city->id, 'name' => 'قرية ناصر']);
    }

    public function test_the_same_region_name_may_appear_in_two_different_cities(): void
    {
        // Arrange
        $tripoli = City::factory()->create(['name' => 'طرابلس']);
        $benghazi = City::factory()->create(['name' => 'بنغازي']);

        // Act
        Region::factory()->create(['city_id' => $tripoli->id, 'name' => 'الهضبة']);
        Region::factory()->create(['city_id' => $benghazi->id, 'name' => 'الهضبة']);

        // Assert
        $this->assertSame(2, Region::query()->where('name', 'الهضبة')->count());
    }

    public function test_a_city_lists_its_regions_in_name_order(): void
    {
        // Arrange
        $city = City::factory()->create();
        Region::factory()->create(['city_id' => $city->id, 'name' => 'زناتة']);
        Region::factory()->create(['city_id' => $city->id, 'name' => 'السبعة']);

        // Act
        $names = $city->regions()->pluck('name')->all();

        // Assert
        $this->assertSame(['السبعة', 'زناتة'], $names);
    }

    public function test_a_region_knows_the_city_it_belongs_to(): void
    {
        // Arrange
        $city = City::factory()->create(['name' => 'مصراتة']);
        $region = Region::factory()->create(['city_id' => $city->id]);

        // Act
        $owner = $region->city;

        // Assert
        $this->assertTrue($owner->is($city));
    }

    public function test_force_deleting_a_city_deletes_the_regions_inside_it(): void
    {
        // Arrange — the database-level cascade, which only a real delete exercises. A soft
        // delete leaves the city row in place, so nothing cascades from it.
        $city = City::factory()->create();
        $region = Region::factory()->create(['city_id' => $city->id]);
        $survivor = Region::factory()->create();

        // Act
        $city->forceDelete();

        // Assert
        $this->assertSame(0, Region::withTrashed()->whereKey($region->id)->count());
        $this->assertDatabaseHas('regions', ['id' => $survivor->id]);
    }

    public function test_a_region_cannot_belong_to_a_city_that_does_not_exist(): void
    {
        // Assert
        $this->expectException(QueryException::class);

        // Act
        Region::factory()->create(['city_id' => 999_999]);
    }

    public function test_a_region_may_have_no_shipping_code_or_branch(): void
    {
        // Act
        $region = Region::factory()->create(['code' => null, 'darb_branch' => null]);

        // Assert
        $stored = $region->fresh();
        $this->assertNull($stored->code);
        $this->assertNull($stored->darb_branch);
    }
}
