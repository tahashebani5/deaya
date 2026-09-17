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
 * Regions — the neighbourhoods inside a city.
 *
 * Nested under their city on purpose: a region has no life of its own, and routing it through
 * `/cities/{city}/regions/{region}` makes another city's region a 404 by construction rather
 * than by a check every method has to remember.
 *
 * Guarded by the city's own permissions — `cities.view` to read, `cities.manage` to change.
 *
 * Arrange - Act - Assert throughout.
 */
class RegionTest extends TestCase
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
            'name' => 'سوق الجمعة',
            'code' => 's18',
            'darb_branch' => 'سوق الجمعة، طرابلس',
        ], $overrides);
    }

    // ─────────────────────────────── listing ───────────────────────────────

    public function test_a_user_with_view_permission_can_list_a_citys_regions(): void
    {
        // Arrange
        $city = City::factory()->create();
        Region::factory()->create(['city_id' => $city->id, 'name' => 'زناتة']);
        Region::factory()->create(['city_id' => $city->id, 'name' => 'السبعة']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$city->id}/regions");

        // Assert — alphabetical, so a picker renders the same way every time
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'السبعة')
            ->assertJsonPath('data.1.name', 'زناتة')
            ->assertJsonStructure([
                'data' => [['id', 'city_id', 'name', 'code', 'darb_branch', 'latitude', 'longitude']],
                'meta' => ['current_page', 'per_page', 'last_page', 'total'],
            ]);
    }

    public function test_listing_regions_needs_authentication(): void
    {
        // Arrange
        $city = City::factory()->create();

        // Act
        $response = $this->getJson("/api/v1/cities/{$city->id}/regions");

        // Assert
        $response->assertUnauthorized();
    }

    public function test_a_user_granted_nothing_may_not_list_regions(): void
    {
        // Arrange
        $city = City::factory()->create();
        Region::factory()->create(['city_id' => $city->id]);
        $headers = $this->outsider();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$city->id}/regions");

        // Assert
        $response->assertForbidden()->assertJsonPath('status', false);
    }

    public function test_the_region_list_only_shows_that_citys_own_regions(): void
    {
        // Arrange
        $tripoli = City::factory()->create();
        $benghazi = City::factory()->create();
        Region::factory()->create(['city_id' => $tripoli->id, 'name' => 'زناتة']);
        Region::factory()->create(['city_id' => $benghazi->id, 'name' => 'الكيش']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$tripoli->id}/regions");

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'زناتة');
    }

    public function test_the_region_list_can_be_searched(): void
    {
        // Arrange
        $city = City::factory()->create();
        Region::factory()->create(['city_id' => $city->id, 'name' => 'سوق الجمعة']);
        Region::factory()->create(['city_id' => $city->id, 'name' => 'الفرناج']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson("/api/v1/cities/{$city->id}/regions?search=".urlencode('سوق'));

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'سوق الجمعة');
    }

    public function test_the_region_list_is_paginated(): void
    {
        // Arrange
        $city = City::factory()->create();
        Region::factory()->count(12)->create(['city_id' => $city->id]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$city->id}/regions?per_page=5");

        // Assert
        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 12);
    }

    public function test_a_city_with_no_regions_lists_an_empty_array(): void
    {
        // Arrange
        $city = City::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$city->id}/regions");

        // Assert
        $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }

    public function test_listing_the_regions_of_a_city_that_does_not_exist_is_not_found(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/cities/999999/regions');

        // Assert
        $response->assertNotFound();
    }

    // ─────────────────────────────── showing ───────────────────────────────

    public function test_it_shows_one_region(): void
    {
        // Arrange
        $city = City::factory()->create();
        $region = Region::factory()->create(['city_id' => $city->id, 'name' => 'زناتة', 'code' => 's18']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$city->id}/regions/{$region->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.name', 'زناتة')
            ->assertJsonPath('data.code', 's18')
            ->assertJsonPath('data.city_id', $city->id);
    }

    public function test_a_region_of_another_city_is_not_found(): void
    {
        // Arrange
        $tripoli = City::factory()->create();
        $benghazi = City::factory()->create();
        $region = Region::factory()->create(['city_id' => $benghazi->id]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$tripoli->id}/regions/{$region->id}");

        // Assert
        $response->assertNotFound();
    }

    // ─────────────────────────────── creating ───────────────────────────────

    public function test_a_user_with_manage_permission_adds_a_region_to_a_city(): void
    {
        // Arrange
        $city = City::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/cities/{$city->id}/regions", $this->payload());

        // Assert
        $response->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'تم إضافة المنطقة بنجاح')
            ->assertJsonPath('data.name', 'سوق الجمعة')
            ->assertJsonPath('data.city_id', $city->id);
        $this->assertDatabaseHas('regions', ['city_id' => $city->id, 'name' => 'سوق الجمعة']);
    }

    public function test_a_region_can_be_added_without_a_shipping_code_or_branch(): void
    {
        // Arrange
        $city = City::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/cities/{$city->id}/regions", [
            'name' => 'قمودة',
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.code', null)
            ->assertJsonPath('data.darb_branch', null);
    }

    public function test_a_region_can_be_added_with_a_pin(): void
    {
        // Arrange
        $city = City::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/cities/{$city->id}/regions",
            $this->payload(['latitude' => 32.3754, 'longitude' => 15.0925]),
        );

        // Assert
        $response->assertCreated();
        $this->assertSame(32.3754, $response->json('data.latitude'));
        $this->assertSame(15.0925, $response->json('data.longitude'));
    }

    public function test_adding_a_region_needs_authentication(): void
    {
        // Arrange
        $city = City::factory()->create();

        // Act
        $response = $this->postJson("/api/v1/cities/{$city->id}/regions", $this->payload());

        // Assert
        $response->assertUnauthorized();
    }

    public function test_a_viewer_may_not_add_a_region(): void
    {
        // Arrange
        $city = City::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/cities/{$city->id}/regions", $this->payload());

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseMissing('regions', ['name' => 'سوق الجمعة']);
    }

    public function test_one_city_cannot_list_the_same_region_twice(): void
    {
        // Arrange
        $city = City::factory()->create();
        Region::factory()->create(['city_id' => $city->id, 'name' => 'سوق الجمعة']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/cities/{$city->id}/regions", $this->payload());

        // Assert — a readable 422, never the database's own error
        $response->assertStatus(422)->assertJsonValidationErrors('name');
        $this->assertSame(1, $city->regions()->where('name', 'سوق الجمعة')->count());
    }

    public function test_two_cities_may_each_have_a_region_with_the_same_name(): void
    {
        // Arrange
        $tripoli = City::factory()->create();
        $benghazi = City::factory()->create();
        Region::factory()->create(['city_id' => $tripoli->id, 'name' => 'الهضبة']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/cities/{$benghazi->id}/regions", $this->payload(['name' => 'الهضبة']));

        // Assert
        $response->assertCreated();
        $this->assertSame(2, Region::query()->where('name', 'الهضبة')->count());
    }

    public function test_adding_a_region_to_a_city_that_does_not_exist_is_not_found(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/cities/999999/regions', $this->payload());

        // Assert
        $response->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidRegionPayloads')]
    public function test_it_rejects_an_invalid_region(array $overrides, string $invalidField): void
    {
        // Arrange
        $city = City::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/cities/{$city->id}/regions", $this->payload($overrides));

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors($invalidField);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidRegionPayloads(): array
    {
        return [
            'no name' => [['name' => null], 'name'],
            'name too short' => [['name' => 'ز'], 'name'],
            'name too long' => [['name' => str_repeat('ز', 101)], 'name'],
            'code too long' => [['code' => str_repeat('s', 21)], 'code'],
            'branch too long' => [['darb_branch' => str_repeat('ب', 256)], 'darb_branch'],
            'latitude out of range' => [['latitude' => 91, 'longitude' => 13], 'latitude'],
            'longitude out of range' => [['latitude' => 32, 'longitude' => 181], 'longitude'],
            'half a pin — latitude only' => [['latitude' => 32.8], 'longitude'],
            'half a pin — longitude only' => [['longitude' => 13.1], 'latitude'],
        ];
    }

    public function test_the_client_cannot_move_a_region_to_another_city_when_creating_it(): void
    {
        // Arrange
        $tripoli = City::factory()->create();
        $benghazi = City::factory()->create();
        $headers = $this->manager();

        // Act — the URL says طرابلس; the body tries to say بنغازي
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/cities/{$tripoli->id}/regions",
            $this->payload(['city_id' => $benghazi->id]),
        );

        // Assert — the URL wins, always
        $response->assertCreated()->assertJsonPath('data.city_id', $tripoli->id);
        $this->assertSame(0, $benghazi->regions()->count());
    }

    // ─────────────────────────────── updating ───────────────────────────────

    public function test_a_user_with_manage_permission_updates_a_region(): void
    {
        // Arrange
        $city = City::factory()->create();
        $region = Region::factory()->create(['city_id' => $city->id, 'name' => 'قديم']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/cities/{$city->id}/regions/{$region->id}",
            $this->payload(['name' => 'جديد', 'code' => 's21']),
        );

        // Assert
        $response->assertOk()
            ->assertJsonPath('message', 'تم تحديث المنطقة بنجاح')
            ->assertJsonPath('data.name', 'جديد')
            ->assertJsonPath('data.code', 's21');
        $this->assertDatabaseHas('regions', ['id' => $region->id, 'name' => 'جديد', 'code' => 's21']);
    }

    public function test_a_region_saved_without_changing_its_name_does_not_collide_with_itself(): void
    {
        // Arrange
        $city = City::factory()->create();
        $region = Region::factory()->create(['city_id' => $city->id, 'name' => 'زناتة']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/cities/{$city->id}/regions/{$region->id}",
            $this->payload(['name' => 'زناتة', 'code' => 's19']),
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.code', 's19');
    }

    public function test_a_region_cannot_take_another_regions_name_in_the_same_city(): void
    {
        // Arrange
        $city = City::factory()->create();
        Region::factory()->create(['city_id' => $city->id, 'name' => 'زناتة']);
        $region = Region::factory()->create(['city_id' => $city->id, 'name' => 'السبعة']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/cities/{$city->id}/regions/{$region->id}",
            $this->payload(['name' => 'زناتة']),
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('name');
        $this->assertDatabaseHas('regions', ['id' => $region->id, 'name' => 'السبعة']);
    }

    public function test_updating_a_region_of_another_city_is_not_found(): void
    {
        // Arrange
        $tripoli = City::factory()->create();
        $benghazi = City::factory()->create();
        $region = Region::factory()->create(['city_id' => $benghazi->id, 'name' => 'الكيش']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/cities/{$tripoli->id}/regions/{$region->id}",
            $this->payload(['name' => 'محاولة']),
        );

        // Assert
        $response->assertNotFound();
        $this->assertDatabaseHas('regions', ['id' => $region->id, 'name' => 'الكيش']);
    }

    public function test_a_viewer_may_not_update_a_region(): void
    {
        // Arrange
        $city = City::factory()->create();
        $region = Region::factory()->create(['city_id' => $city->id, 'name' => 'زناتة']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/cities/{$city->id}/regions/{$region->id}",
            $this->payload(['name' => 'محاولة']),
        );

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('regions', ['id' => $region->id, 'name' => 'زناتة']);
    }

    // ─────────────────────────────── deleting ───────────────────────────────

    public function test_a_user_with_manage_permission_deletes_a_region(): void
    {
        // Arrange
        $city = City::factory()->create();
        $region = Region::factory()->create(['city_id' => $city->id]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)
            ->deleteJson("/api/v1/cities/{$city->id}/regions/{$region->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('message', 'تم حذف المنطقة بنجاح')
            ->assertJsonPath('data', null);
        // Soft deleted rather than erased: the row leaves the live set but stays on record.
        $this->assertSoftDeleted('regions', ['id' => $region->id]);
    }

    public function test_deleting_a_region_of_another_city_is_not_found(): void
    {
        // Arrange
        $tripoli = City::factory()->create();
        $benghazi = City::factory()->create();
        $region = Region::factory()->create(['city_id' => $benghazi->id]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)
            ->deleteJson("/api/v1/cities/{$tripoli->id}/regions/{$region->id}");

        // Assert
        $response->assertNotFound();
        $this->assertDatabaseHas('regions', ['id' => $region->id]);
    }

    public function test_a_viewer_may_not_delete_a_region(): void
    {
        // Arrange
        $city = City::factory()->create();
        $region = Region::factory()->create(['city_id' => $city->id]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)
            ->deleteJson("/api/v1/cities/{$city->id}/regions/{$region->id}");

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('regions', ['id' => $region->id]);
    }

    /**
     * The invariant that protects checkout: a city flagged `is_region_required` must never be
     * left with an empty region list, or the customer is asked to pick from nothing.
     */
    public function test_the_last_region_of_a_city_that_requires_one_cannot_be_deleted(): void
    {
        // Arrange
        $city = City::factory()->requiringRegion()->create(['name' => 'طرابلس']);
        $region = Region::factory()->create(['city_id' => $city->id]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)
            ->deleteJson("/api/v1/cities/{$city->id}/regions/{$region->id}");

        // Assert
        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'لا يمكن حذف آخر منطقة في مدينة تتطلب اختيار منطقة — ألغِ اشتراط المنطقة أولاً');
        $this->assertDatabaseHas('regions', ['id' => $region->id]);
    }

    public function test_one_of_several_regions_can_be_deleted_even_when_a_region_is_required(): void
    {
        // Arrange
        $city = City::factory()->requiringRegion()->create();
        $region = Region::factory()->create(['city_id' => $city->id]);
        Region::factory()->create(['city_id' => $city->id]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)
            ->deleteJson("/api/v1/cities/{$city->id}/regions/{$region->id}");

        // Assert
        $response->assertOk();
        // Soft deleted rather than erased: the row leaves the live set but stays on record.
        $this->assertSoftDeleted('regions', ['id' => $region->id]);
    }

    public function test_the_last_region_of_a_city_that_does_not_require_one_can_be_deleted(): void
    {
        // Arrange
        $city = City::factory()->create(['is_region_required' => false]);
        $region = Region::factory()->create(['city_id' => $city->id]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)
            ->deleteJson("/api/v1/cities/{$city->id}/regions/{$region->id}");

        // Assert
        $response->assertOk();
        // Soft deleted rather than erased: the row leaves the live set but stays on record.
        $this->assertSoftDeleted('regions', ['id' => $region->id]);
    }
}
