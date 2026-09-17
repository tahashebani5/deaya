<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Cities — the delivery map's top level.
 *
 * Two permissions guard it: `cities.view` to read the map, because anyone taking an order needs
 * it to fill in an address, and `cities.manage` to curate it, which is a rarer job. Both are
 * declared on the routes, so these tests exercise the guard exactly as a client meets it.
 *
 * Arrange - Act - Assert throughout.
 */
class CityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions are defined by the code, so the guards under test only exist once these
        // rows do.
        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    /**
     * A user holding exactly the permissions named, granted directly rather than through a
     * role: a test then states the access it needs instead of depending on how RoleSeeder
     * happens to shape a job today.
     *
     * @return array<string, string>
     */
    private function auth(PermissionName ...$permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (PermissionName $p) => $p->value, $permissions));

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * May read the delivery map but not change it.
     *
     * @return array<string, string>
     */
    private function viewer(): array
    {
        return $this->auth(PermissionName::ViewDeliveryLocations);
    }

    /**
     * May curate the delivery map.
     *
     * @return array<string, string>
     */
    private function manager(): array
    {
        return $this->auth(
            PermissionName::ViewDeliveryLocations,
            PermissionName::ManageDeliveryLocations,
        );
    }

    /**
     * Authenticated, but granted nothing at all.
     *
     * @return array<string, string>
     */
    private function outsider(): array
    {
        return $this->auth();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'طرابلس',
            'is_region_required' => true,
            'delivery_price' => '15.00',
            'darb_branch' => 'زناتة، طرابلس',
        ], $overrides);
    }

    // ─────────────────────────────── listing ───────────────────────────────

    public function test_a_user_with_view_permission_can_list_the_cities(): void
    {
        // Arrange
        City::factory()->create(['name' => 'مصراتة']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/cities');

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.0.name', 'مصراتة')
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [['id', 'name', 'is_region_required', 'delivery_price', 'darb_branch', 'latitude', 'longitude', 'regions_count']],
                'meta' => ['current_page', 'per_page', 'last_page', 'total'],
            ]);
    }

    public function test_listing_the_cities_needs_authentication(): void
    {
        // Act
        $response = $this->getJson('/api/v1/cities');

        // Assert
        $response->assertUnauthorized();
    }

    public function test_a_user_granted_nothing_may_not_list_the_cities(): void
    {
        // Arrange
        City::factory()->create();
        $headers = $this->outsider();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/cities');

        // Assert
        $response->assertForbidden()->assertJsonPath('status', false);
    }

    public function test_a_user_granted_nothing_may_not_see_a_city(): void
    {
        // Arrange
        $city = City::factory()->create();
        $headers = $this->outsider();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$city->id}");

        // Assert
        $response->assertForbidden();
    }

    public function test_the_city_list_is_paginated(): void
    {
        // Arrange
        City::factory()->count(20)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/cities?per_page=5&page=2');

        // Assert
        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 20);
    }

    public function test_an_absurd_per_page_is_clamped(): void
    {
        // Arrange
        City::factory()->count(3)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/cities?per_page=100000');

        // Assert
        $response->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_a_per_page_of_zero_falls_back_to_one(): void
    {
        // Arrange
        City::factory()->count(3)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/cities?per_page=0');

        // Assert
        $response->assertOk()->assertJsonPath('meta.per_page', 1);
    }

    public function test_the_list_can_be_searched_by_name(): void
    {
        // Arrange
        City::factory()->create(['name' => 'طرابلس']);
        City::factory()->create(['name' => 'بنغازي']);
        $headers = $this->viewer();

        // Act — percent-encoded, as any real client sends a query string
        $response = $this->withHeaders($headers)->getJson('/api/v1/cities?search='.urlencode('طرابل'));

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'طرابلس');
    }

    public function test_the_list_can_be_searched_by_shipping_branch(): void
    {
        // Arrange
        City::factory()->create(['name' => 'زليتن', 'darb_branch' => 'زليتن، الخمس']);
        City::factory()->create(['name' => 'سبها', 'darb_branch' => 'سبها، سبها']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/cities?search='.urlencode('الخمس'));

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'زليتن');
    }

    public function test_the_list_can_be_filtered_to_cities_that_require_a_region(): void
    {
        // Arrange
        City::factory()->requiringRegion()->create(['name' => 'طرابلس']);
        City::factory()->create(['name' => 'الخمس']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/cities?is_region_required=1');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'طرابلس');
    }

    public function test_the_list_can_be_filtered_to_cities_that_still_have_no_price(): void
    {
        // Arrange
        City::factory()->withoutPrice()->create(['name' => 'تساوة']);
        City::factory()->create(['name' => 'الخمس']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/cities?has_price=0');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'تساوة');
    }

    public function test_an_empty_list_is_an_empty_array(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/cities');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_the_list_says_how_many_regions_each_city_has_without_listing_them(): void
    {
        // Arrange
        $city = City::factory()->create();
        Region::factory()->count(3)->create(['city_id' => $city->id]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/cities');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.regions_count', 3)
            ->assertJsonMissingPath('data.0.regions');
    }

    // ─────────────────────────────── showing ───────────────────────────────

    public function test_it_shows_one_city_with_its_regions(): void
    {
        // Arrange
        $city = City::factory()->create(['name' => 'طرابلس']);
        Region::factory()->create(['city_id' => $city->id, 'name' => 'سوق الجمعة']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$city->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.name', 'طرابلس')
            ->assertJsonCount(1, 'data.regions')
            ->assertJsonPath('data.regions.0.name', 'سوق الجمعة');
    }

    public function test_showing_a_city_that_does_not_exist_is_not_found(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/cities/999999');

        // Assert
        $response->assertNotFound()->assertJsonPath('status', false);
    }

    public function test_a_pin_is_returned_as_numbers_not_strings(): void
    {
        // Arrange
        $city = City::factory()->pinnedAt(32.8872, 13.1913)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$city->id}");

        // Assert
        $response->assertOk();
        $this->assertSame(32.8872, $response->json('data.latitude'));
        $this->assertSame(13.1913, $response->json('data.longitude'));
    }

    // ─────────────────────────────── creating ───────────────────────────────

    public function test_a_user_with_manage_permission_creates_a_city(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/cities', $this->payload());

        // Assert
        $response->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'تم إضافة المدينة بنجاح')
            ->assertJsonPath('data.name', 'طرابلس')
            ->assertJsonPath('data.is_region_required', true)
            ->assertJsonPath('data.delivery_price', '15.00')
            ->assertJsonPath('data.regions_count', 0);
        $this->assertDatabaseHas('cities', ['name' => 'طرابلس', 'delivery_price' => '15.00']);
    }

    public function test_a_city_can_be_created_with_a_pin(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/cities', $this->payload([
            'latitude' => 32.8872,
            'longitude' => 13.1913,
        ]));

        // Assert
        $response->assertCreated();
        $this->assertSame(32.8872, $response->json('data.latitude'));
        $this->assertSame(13.1913, $response->json('data.longitude'));
    }

    public function test_a_city_can_be_created_before_its_price_is_agreed(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/cities', $this->payload([
            'delivery_price' => null,
        ]));

        // Assert
        $response->assertCreated()->assertJsonPath('data.delivery_price', null);
    }

    public function test_an_office_pickup_city_is_created_free_and_without_regions(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/cities', [
            'name' => 'إستلام مكتب(قرجي)',
            'is_region_required' => false,
            'delivery_price' => 0,
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.delivery_price', '0.00')
            ->assertJsonPath('data.is_region_required', false);
    }

    public function test_creating_a_city_needs_authentication(): void
    {
        // Act
        $response = $this->postJson('/api/v1/cities', $this->payload());

        // Assert
        $response->assertUnauthorized();
    }

    public function test_a_viewer_may_not_create_a_city(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/cities', $this->payload());

        // Assert
        $response->assertForbidden()->assertJsonPath('status', false);
        $this->assertDatabaseMissing('cities', ['name' => 'طرابلس']);
    }

    public function test_two_cities_cannot_share_a_name(): void
    {
        // Arrange
        City::factory()->create(['name' => 'طرابلس']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/cities', $this->payload());

        // Assert — a readable 422, never the database's own error
        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonValidationErrors('name');
        $this->assertSame(1, City::query()->where('name', 'طرابلس')->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidCityPayloads')]
    public function test_it_rejects_an_invalid_city(array $overrides, string $invalidField): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/cities', $this->payload($overrides));

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors($invalidField);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidCityPayloads(): array
    {
        return [
            'no name' => [['name' => null], 'name'],
            'name too short' => [['name' => 'ط'], 'name'],
            'name too long' => [['name' => str_repeat('ط', 101)], 'name'],
            'negative price' => [['delivery_price' => -1], 'delivery_price'],
            'price is not a number' => [['delivery_price' => 'مجاناً'], 'delivery_price'],
            'price beyond the column' => [['delivery_price' => 99999999999], 'delivery_price'],
            'branch too long' => [['darb_branch' => str_repeat('ب', 256)], 'darb_branch'],
            'flag is not a boolean' => [['is_region_required' => 'ربما'], 'is_region_required'],
            'latitude below range' => [['latitude' => -91, 'longitude' => 13], 'latitude'],
            'latitude above range' => [['latitude' => 91, 'longitude' => 13], 'latitude'],
            'longitude below range' => [['latitude' => 32, 'longitude' => -181], 'longitude'],
            'longitude above range' => [['latitude' => 32, 'longitude' => 181], 'longitude'],
            'half a pin — latitude only' => [['latitude' => 32.8], 'longitude'],
            'half a pin — longitude only' => [['longitude' => 13.1], 'latitude'],
        ];
    }

    public function test_the_client_cannot_choose_the_id(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/cities', $this->payload(['id' => 999999]));

        // Assert
        $response->assertCreated();
        $this->assertNotSame(999999, $response->json('data.id'));
        $this->assertDatabaseMissing('cities', ['id' => 999999]);
    }

    // ─────────────────────────────── updating ───────────────────────────────

    public function test_a_user_with_manage_permission_updates_a_city(): void
    {
        // Arrange
        $city = City::factory()->create(['name' => 'قديم', 'delivery_price' => '10.00']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/cities/{$city->id}", $this->payload([
            'name' => 'جديد',
            'delivery_price' => '25.50',
        ]));

        // Assert
        $response->assertOk()
            ->assertJsonPath('message', 'تم تحديث المدينة بنجاح')
            ->assertJsonPath('data.name', 'جديد')
            ->assertJsonPath('data.delivery_price', '25.50');
        $this->assertDatabaseHas('cities', ['id' => $city->id, 'name' => 'جديد', 'delivery_price' => '25.50']);
    }

    public function test_a_city_saved_without_changing_its_name_does_not_collide_with_itself(): void
    {
        // Arrange
        $city = City::factory()->create(['name' => 'مصراتة']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/cities/{$city->id}",
            $this->payload(['name' => 'مصراتة', 'delivery_price' => '30.00']),
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.delivery_price', '30.00');
    }

    public function test_a_city_cannot_take_another_citys_name(): void
    {
        // Arrange
        City::factory()->create(['name' => 'طرابلس']);
        $city = City::factory()->create(['name' => 'مصراتة']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/cities/{$city->id}",
            $this->payload(['name' => 'طرابلس']),
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('name');
        $this->assertDatabaseHas('cities', ['id' => $city->id, 'name' => 'مصراتة']);
    }

    public function test_updating_can_clear_a_pin(): void
    {
        // Arrange
        $city = City::factory()->pinnedAt(32.8872, 13.1913)->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/cities/{$city->id}", $this->payload([
            'name' => $city->name,
            'latitude' => null,
            'longitude' => null,
        ]));

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.latitude', null)
            ->assertJsonPath('data.longitude', null);
    }

    public function test_a_viewer_may_not_update_a_city(): void
    {
        // Arrange
        $city = City::factory()->create(['name' => 'مصراتة']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/cities/{$city->id}",
            $this->payload(['name' => 'محاولة']),
        );

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('cities', ['id' => $city->id, 'name' => 'مصراتة']);
    }

    public function test_updating_a_city_that_does_not_exist_is_not_found(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson('/api/v1/cities/999999', $this->payload());

        // Assert
        $response->assertNotFound();
    }

    // ─────────────────────────────── deleting ───────────────────────────────

    public function test_a_user_with_manage_permission_deletes_a_city(): void
    {
        // Arrange
        $city = City::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/cities/{$city->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'تم حذف المدينة بنجاح')
            ->assertJsonPath('data', null);
        // Soft deleted rather than erased: the row leaves the live set but stays on record.
        $this->assertSoftDeleted('cities', ['id' => $city->id]);
    }

    public function test_deleting_a_city_deletes_the_regions_inside_it(): void
    {
        // Arrange
        $city = City::factory()->create();
        $region = Region::factory()->create(['city_id' => $city->id]);
        $survivor = Region::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/cities/{$city->id}");

        // Assert
        $response->assertOk();
        // Soft deleted rather than erased: the row leaves the live set but stays on record.
        $this->assertSoftDeleted('regions', ['id' => $region->id]);
        $this->assertDatabaseHas('regions', ['id' => $survivor->id, 'deleted_at' => null]);
    }

    public function test_a_viewer_may_not_delete_a_city(): void
    {
        // Arrange
        $city = City::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/cities/{$city->id}");

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('cities', ['id' => $city->id]);
    }

    public function test_deleting_a_city_that_does_not_exist_is_not_found(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->deleteJson('/api/v1/cities/999999');

        // Assert
        $response->assertNotFound();
    }
}
