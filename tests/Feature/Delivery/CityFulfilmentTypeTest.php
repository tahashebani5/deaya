<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Enums\FulfilmentType;
use App\Domain\Delivery\Models\City;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use Database\Seeders\DeliveryLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Whether a city is delivered to or collected from, as a stored fact rather than a guess.
 *
 * Until now the only way to know that إستلام مكتب(قرجي) is an office was to read its **name**.
 * Orders are about to depend on that answer — the status an order moves to when it leaves the
 * workshop is «استلام مكتب» for one kind of city and «جاري التوصيل» for the other, and the server
 * decides which. A rule that keys off an Arabic display name breaks silently the first time an
 * administrator fixes a typo, and the symptom appears on an order days later.
 *
 * So the fact is a column, and this is the test that says so.
 *
 * Arrange - Act - Assert throughout.
 */
class CityFulfilmentTypeTest extends TestCase
{
    use RefreshDatabase;

    private const OFFICE_BRANCHES = ['إستلام مكتب(قرجي)', 'إستلام مكتب(ولي العهد)'];

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

    /**
     * @return array<string, string>
     */
    private function viewer(): array
    {
        return $this->auth(PermissionName::ViewDeliveryLocations);
    }

    /**
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

    // ─────────────────────────── the stored fact ───────────────────────────

    public function test_a_city_is_somewhere_we_deliver_to_unless_it_says_otherwise(): void
    {
        // Act
        $city = City::factory()->create();

        // Assert — the overwhelmingly common case must not need stating.
        $this->assertSame(FulfilmentType::Delivery, $city->fulfilment_type);
        $this->assertFalse($city->isOfficePickup());
    }

    public function test_an_office_branch_reports_itself_as_one(): void
    {
        // Act
        $office = City::factory()->officePickup()->create();

        // Assert
        $this->assertSame(FulfilmentType::OfficePickup, $office->fulfilment_type);
        $this->assertTrue($office->isOfficePickup());
    }

    /**
     * The whole reason the column exists: the answer survives the name changing.
     */
    public function test_renaming_an_office_branch_does_not_turn_it_into_a_delivery_city(): void
    {
        // Arrange
        $office = City::factory()->officePickup()->create(['name' => 'إستلام مكتب(قرجي)']);

        // Act — an administrator tidies the spelling and drops the words the old rule matched on.
        $office->update(['name' => 'فرع قرجي']);

        // Assert
        $this->assertTrue($office->fresh()->isOfficePickup());
    }

    // ───────────────────────────── the seeder ─────────────────────────────

    public function test_the_seeder_marks_both_office_branches_as_office_pickup(): void
    {
        // Act
        $this->seed(DeliveryLocationSeeder::class);

        // Assert
        foreach (self::OFFICE_BRANCHES as $branch) {
            $office = City::query()->where('name', $branch)->sole();

            $this->assertTrue(
                $office->isOfficePickup(),
                "{$branch} should be an office-pickup branch",
            );
        }
    }

    public function test_the_seeder_leaves_every_other_city_as_a_delivery_destination(): void
    {
        // Arrange
        $this->seed(DeliveryLocationSeeder::class);

        // Act
        $pickupCount = City::query()
            ->where('fulfilment_type', FulfilmentType::OfficePickup)
            ->count();

        // Assert — exactly the two branches, and nothing swept up by a loose match.
        $this->assertSame(2, $pickupCount);
    }

    // ────────────────────────────── the API ──────────────────────────────

    public function test_a_city_carries_its_fulfilment_type_and_the_arabic_label_for_it(): void
    {
        // Arrange
        $office = City::factory()->officePickup()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$office->id}");

        // Assert — the value for logic, the label so the app keeps no translation table of its own.
        $response->assertOk()
            ->assertJsonPath('data.fulfilment_type', 'office_pickup')
            ->assertJsonPath('data.fulfilment_type_label', 'استلام مكتب')
            ->assertJsonPath('data.is_office_pickup', true);
    }

    public function test_a_manager_can_add_an_office_branch(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/cities', $this->payload([
            'name' => 'إستلام مكتب(سوق الجمعة)',
            'fulfilment_type' => 'office_pickup',
            'is_region_required' => false,
            'delivery_price' => '0.00',
            'darb_branch' => null,
        ]));

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.fulfilment_type', 'office_pickup');

        $this->assertDatabaseHas('cities', [
            'name' => 'إستلام مكتب(سوق الجمعة)',
            'fulfilment_type' => 'office_pickup',
        ]);
    }

    public function test_a_city_added_without_saying_is_a_delivery_destination(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/cities', $this->payload());

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.fulfilment_type', 'delivery')
            ->assertJsonPath('data.is_office_pickup', false);
    }

    public function test_a_manager_can_turn_a_city_into_an_office_branch(): void
    {
        // Arrange
        $city = City::factory()->create(['name' => 'قرجي']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/cities/{$city->id}", $this->payload([
            'name' => 'قرجي',
            'fulfilment_type' => 'office_pickup',
        ]));

        // Assert
        $response->assertOk()->assertJsonPath('data.fulfilment_type', 'office_pickup');
        $this->assertDatabaseHas('cities', ['id' => $city->id, 'fulfilment_type' => 'office_pickup']);
    }

    /**
     * A PUT that leaves the field out must not silently move a branch back to being delivered
     * to — the same rule `is_active` follows on customers.
     */
    public function test_updating_a_city_without_naming_the_type_leaves_it_alone(): void
    {
        // Arrange
        $office = City::factory()->officePickup()->create(['name' => 'إستلام مكتب(قرجي)']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/cities/{$office->id}", [
            'name' => 'إستلام مكتب(قرجي)',
            'is_region_required' => false,
            'delivery_price' => '0.00',
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('data.fulfilment_type', 'office_pickup');
        $this->assertTrue($office->fresh()->isOfficePickup());
    }

    public function test_a_fulfilment_type_the_system_does_not_know_is_refused(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/cities', $this->payload([
            'fulfilment_type' => 'teleportation',
        ]));

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('fulfilment_type');
        $this->assertDatabaseMissing('cities', ['name' => 'طرابلس']);
    }

    // ──────────────────────────── the enum itself ────────────────────────────

    public function test_every_fulfilment_type_has_an_arabic_label(): void
    {
        // Act
        $labels = array_map(fn (FulfilmentType $type) => $type->label(), FulfilmentType::cases());

        // Assert — a case added without one would render as a blank chip in the app.
        $this->assertCount(count(FulfilmentType::cases()), array_filter($labels));
    }
}
