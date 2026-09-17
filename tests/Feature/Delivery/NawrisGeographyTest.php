<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * What Nawris calls the places we already have names for.
 *
 * **The mapping lives on our own rows, so nobody ever picks a Nawris destination by hand** — an
 * order already knows its city, and the city knows its government. See NAWRIS-INTEGRATION.md §4.
 *
 * There is precedent for both columns: `cities.darb_branch` and `regions.code` are already another
 * carrier's vocabulary parked on our row, unvalidated because we do not own it. These behave the
 * same way, and this file asserts that they do.
 *
 * Arrange - Act - Assert throughout.
 */
class NawrisGeographyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    /**
     * @return array<string, string>
     */
    private function auth(PermissionName ...$permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (PermissionName $p) => $p->value, $permissions));

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    // ── the columns ──────────────────────────────────────────────────────────────────────

    public function test_a_city_carries_the_government_nawris_knows_it_by(): void
    {
        // Act
        $city = City::create(['name' => 'طرابلس', 'nawris_government_id' => '5']);

        // Assert
        $this->assertDatabaseHas('cities', ['name' => 'طرابلس', 'nawris_government_id' => '5']);
        $this->assertSame('5', $city->fresh()->nawris_government_id);
    }

    public function test_a_region_carries_the_area_nawris_knows_it_by(): void
    {
        // Act
        $region = Region::factory()->create(['nawris_area_id' => '87']);

        // Assert
        $this->assertSame('87', $region->fresh()->nawris_area_id);
    }

    public function test_both_are_optional_because_most_places_are_not_nawris_destinations(): void
    {
        // Arrange & Act — the two «استلام مكتب» rows never leave the building, and the business
        // may use its own courier for some cities. Dispatch refuses an unmapped delivery city by
        // name; the column itself simply stays null.
        $city = City::factory()->create();
        $region = Region::factory()->create();

        // Assert
        $this->assertNull($city->nawris_government_id);
        $this->assertNull($region->nawris_area_id);
    }

    // ── through the API ──────────────────────────────────────────────────────────────────

    public function test_a_city_can_be_created_with_its_nawris_government(): void
    {
        // Arrange
        $headers = $this->auth(PermissionName::ManageDeliveryLocations);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/cities', [
            'name' => 'مصراتة',
            'nawris_government_id' => '12',
        ]);

        // Assert
        $response->assertCreated()->assertJsonPath('data.nawris_government_id', '12');
        $this->assertDatabaseHas('cities', ['name' => 'مصراتة', 'nawris_government_id' => '12']);
    }

    public function test_a_city_update_can_map_and_then_unmap_it(): void
    {
        // Arrange — PUT replaces the whole city, so omitting the field clears it. That is what
        // `UpdateCity` already does with the pin, and «توقفنا عن الشحن لهذه المدينة عبر نورس» has
        // to be expressible.
        $city = City::factory()->create(['nawris_government_id' => null]);
        $headers = $this->auth(PermissionName::ManageDeliveryLocations);

        // Act
        $mapped = $this->withHeaders($headers)
            ->putJson("/api/v1/cities/{$city->id}", ['name' => $city->name, 'nawris_government_id' => '9']);

        $unmapped = $this->withHeaders($headers)
            ->putJson("/api/v1/cities/{$city->id}", ['name' => $city->name]);

        // Assert
        $mapped->assertOk()->assertJsonPath('data.nawris_government_id', '9');
        $unmapped->assertOk()->assertJsonPath('data.nawris_government_id', null);
        $this->assertNull($city->fresh()->nawris_government_id);
    }

    public function test_a_region_can_be_created_with_its_nawris_area(): void
    {
        // Arrange
        $city = City::factory()->create();
        $headers = $this->auth(PermissionName::ManageDeliveryLocations);

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/cities/{$city->id}/regions", [
            'name' => 'قرقارش',
            'nawris_area_id' => '204',
        ]);

        // Assert
        $response->assertCreated()->assertJsonPath('data.nawris_area_id', '204');
    }

    public function test_the_city_resource_carries_the_mapping_so_a_screen_can_show_it(): void
    {
        // Arrange
        $city = City::factory()->create(['nawris_government_id' => '3']);
        $headers = $this->auth(PermissionName::ViewDeliveryLocations);

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$city->id}");

        // Assert
        $response->assertOk()->assertJsonPath('data.nawris_government_id', '3');
    }

    // ── the boundary ─────────────────────────────────────────────────────────────────────

    public function test_an_over_long_government_id_is_refused(): void
    {
        // Arrange — the column is 40, and the value is theirs: we bound the length and validate
        // nothing else, exactly as `darb_branch` does. A format we guessed at would refuse a
        // value their API accepts.
        $headers = $this->auth(PermissionName::ManageDeliveryLocations);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/cities', [
            'name' => 'سبها',
            'nawris_government_id' => str_repeat('9', 41),
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('nawris_government_id');
    }
}
