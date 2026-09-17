<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use Database\Seeders\DeliveryLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The real Libyan delivery map, as it must land in the database.
 *
 * The numbers below are the source export (94 cities, 221 regions) after three deliberate
 * changes, each asserted by its own test: the single إستلام مكتب city becomes two branches, its
 * one region disappears with it, and two double-entered regions in ضواحي الزاوية are seeded once.
 *
 * Arrange - Act - Assert throughout.
 */
class DeliveryLocationSeederTest extends TestCase
{
    use RefreshDatabase;

    private const OFFICE_BRANCHES = ['إستلام مكتب(قرجي)', 'إستلام مكتب(ولي العهد)'];

    public function test_it_seeds_the_whole_delivery_map(): void
    {
        // Act
        $this->seed(DeliveryLocationSeeder::class);

        // Assert
        $this->assertSame(95, City::query()->count());
        $this->assertSame(218, Region::query()->count());
    }

    public function test_office_pickup_is_split_into_two_named_branches(): void
    {
        // Act
        $this->seed(DeliveryLocationSeeder::class);

        // Assert
        foreach (self::OFFICE_BRANCHES as $branch) {
            $this->assertDatabaseHas('cities', ['name' => $branch]);
        }
    }

    public function test_the_single_office_pickup_city_no_longer_exists(): void
    {
        // Act
        $this->seed(DeliveryLocationSeeder::class);

        // Assert
        $this->assertDatabaseMissing('cities', ['name' => 'إستلام مكتب']);
    }

    public function test_picking_up_from_an_office_is_free_and_needs_no_region(): void
    {
        // Act
        $this->seed(DeliveryLocationSeeder::class);

        // Assert
        foreach (self::OFFICE_BRANCHES as $branch) {
            $office = City::query()->where('name', $branch)->sole();

            $this->assertSame('0.00', $office->delivery_price);
            $this->assertFalse($office->is_region_required);
            $this->assertSame(0, $office->regions()->count());
        }
    }

    public function test_the_office_branches_await_their_real_coordinates(): void
    {
        // Act
        $this->seed(DeliveryLocationSeeder::class);

        // Assert — the columns exist and are ready; no pin is invented for a real office
        foreach (self::OFFICE_BRANCHES as $branch) {
            $office = City::query()->where('name', $branch)->sole();

            $this->assertNull($office->latitude);
            $this->assertNull($office->longitude);
        }
    }

    public function test_a_city_keeps_its_delivery_price_and_shipping_branch(): void
    {
        // Act
        $this->seed(DeliveryLocationSeeder::class);

        // Assert
        $tripoli = City::query()->where('name', 'طرابلس')->sole();

        $this->assertSame('15.00', $tripoli->delivery_price);
        $this->assertSame('زناتة، طرابلس', $tripoli->darb_branch);
        $this->assertTrue($tripoli->is_region_required);
    }

    public function test_a_city_whose_price_is_not_set_yet_is_seeded_without_one(): void
    {
        // Act
        $this->seed(DeliveryLocationSeeder::class);

        // Assert
        $this->assertNull(City::query()->where('name', 'تساوة')->sole()->delivery_price);
        $this->assertNull(City::query()->where('name', 'ضواحي الزاوية')->sole()->delivery_price);
        $this->assertNull(City::query()->where('name', 'ضواحي صبراتة')->sole()->delivery_price);
    }

    public function test_regions_are_attached_to_the_city_they_belong_to(): void
    {
        // Act
        $this->seed(DeliveryLocationSeeder::class);

        // Assert
        $tripoli = City::query()->where('name', 'طرابلس')->sole();
        $misrata = City::query()->where('name', 'مصراتة')->sole();

        $this->assertSame(50, $tripoli->regions()->count());
        $this->assertSame(61, $misrata->regions()->count());
        $this->assertTrue($tripoli->regions()->where('name', 'سوق الجمعة')->exists());
    }

    public function test_a_region_keeps_its_shipping_code_and_branch(): void
    {
        // Act
        $this->seed(DeliveryLocationSeeder::class);

        // Assert
        $souqAlJumaa = Region::query()->where('name', 'سوق الجمعة')->sole();

        $this->assertSame('s18', $souqAlJumaa->code);
        $this->assertSame('سوق الجمعة، طرابلس', $souqAlJumaa->darb_branch);
    }

    public function test_a_region_double_entered_in_the_source_is_seeded_once(): void
    {
        // Act
        $this->seed(DeliveryLocationSeeder::class);

        // Assert
        $suburbs = City::query()->where('name', 'ضواحي الزاوية')->sole();

        $this->assertSame(1, $suburbs->regions()->where('name', 'البرناوي')->count());
        $this->assertSame(1, $suburbs->regions()->where('name', 'قرية ناصر')->count());
    }

    public function test_seeding_twice_changes_nothing(): void
    {
        // Arrange
        $this->seed(DeliveryLocationSeeder::class);

        // Act
        $this->seed(DeliveryLocationSeeder::class);

        // Assert
        $this->assertSame(95, City::query()->count());
        $this->assertSame(218, Region::query()->count());
    }

    public function test_reseeding_keeps_coordinates_entered_by_hand(): void
    {
        // Arrange
        $this->seed(DeliveryLocationSeeder::class);
        City::query()->where('name', self::OFFICE_BRANCHES[0])
            ->update(['latitude' => 32.8534000, 'longitude' => 13.1234000]);

        // Act
        $this->seed(DeliveryLocationSeeder::class);

        // Assert
        $office = City::query()->where('name', self::OFFICE_BRANCHES[0])->sole();

        $this->assertSame(32.8534, $office->latitude);
        $this->assertSame(13.1234, $office->longitude);
    }
}
