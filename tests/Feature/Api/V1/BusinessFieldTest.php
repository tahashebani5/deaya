<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Customer\Models\BusinessField;
use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\City;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * مجالات العمل — the trade a customer's shop is in.
 *
 * Two permissions guard it, and the split is the point: `business_fields.view` is granted to
 * every role because the customer form cannot be filled in without the list, while
 * `business_fields.manage` curates the list itself and stays rare.
 *
 * Arrange - Act - Assert throughout.
 */
class BusinessFieldTest extends TestCase
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

    /** May read the list, as everyone taking a customer's details can. */
    private function viewer(): array
    {
        return $this->auth(PermissionName::ViewBusinessFields);
    }

    /** May curate the list. */
    private function manager(): array
    {
        return $this->auth(PermissionName::ViewBusinessFields, PermissionName::ManageBusinessFields);
    }

    // ─────────────────────────── reading ───────────────────────────

    public function test_the_list_comes_back_in_the_business_order(): void
    {
        // Arrange — created out of order on purpose.
        BusinessField::factory()->named('أخرى')->create(['sort_order' => 90]);
        BusinessField::factory()->named('شحن وتوصيل')->create(['sort_order' => 10]);
        BusinessField::factory()->named('بيع ملابس')->create(['sort_order' => 30]);

        // Act
        $response = $this->withHeaders($this->viewer())->getJson('/api/v1/business-fields');

        // Assert — the picker's order is the business's, not the insertion order or the
        // database's idea of alphabetical Arabic.
        $response->assertOk();
        $this->assertSame(
            ['شحن وتوصيل', 'بيع ملابس', 'أخرى'],
            array_column($response->json('data'), 'name'),
        );
    }

    public function test_a_picker_can_ask_for_the_offered_fields_only(): void
    {
        // Arrange
        BusinessField::factory()->named('شحن وتوصيل')->create();
        BusinessField::factory()->named('مجال متوقف')->inactive()->create();

        // Act
        $response = $this->withHeaders($this->viewer())->getJson('/api/v1/business-fields?is_active=1');

        // Assert — the management screen leaves the filter off and sees both.
        $response->assertOk();
        $this->assertSame(['شحن وتوصيل'], array_column($response->json('data'), 'name'));
    }

    public function test_each_row_says_how_many_shops_are_in_that_trade(): void
    {
        // Arrange
        $field = BusinessField::factory()->named('شحن وتوصيل')->create();
        $customer = Customer::factory()->create();
        $city = City::factory()->create();
        $customer->shops()->createMany([
            ['name' => 'محل ١', 'city_id' => $city->id, 'business_field_id' => $field->id],
            ['name' => 'محل ٢', 'city_id' => $city->id, 'business_field_id' => $field->id],
        ]);

        // Act
        $response = $this->withHeaders($this->viewer())->getJson('/api/v1/business-fields');

        // Assert — the number that makes the screen worth opening, and the one that decides
        // whether a delete will be refused.
        $response->assertOk();
        $this->assertSame(2, $response->json('data.0.shops_count'));
    }

    public function test_reading_the_list_needs_the_view_permission(): void
    {
        // Arrange
        BusinessField::factory()->create();
        $headers = $this->auth(PermissionName::ViewCustomers);

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/business-fields');

        // Assert — granted to every role, but still a permission rather than an open door.
        $response->assertForbidden();
    }

    // ─────────────────────────── writing ───────────────────────────

    public function test_a_field_can_be_added(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/business-fields', [
            'name' => 'مطاعم ومقاهي',
            'sort_order' => 40,
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.name', 'مطاعم ومقاهي')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.shops_count', 0);

        $this->assertDatabaseHas('business_fields', ['name' => 'مطاعم ومقاهي', 'sort_order' => 40]);
    }

    public function test_the_same_trade_cannot_be_recorded_twice(): void
    {
        // Arrange
        BusinessField::factory()->named('شحن وتوصيل')->create();

        // Act
        $response = $this->withHeaders($this->manager())->postJson('/api/v1/business-fields', [
            'name' => 'شحن وتوصيل',
        ]);

        // Assert — a readable 422, not a constraint violation surfacing as a 500.
        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_a_name_is_trimmed_before_it_is_stored(): void
    {
        // Arrange — a trailing space is a second «شحن» to a unique index, and nobody would see
        // why the list had two.
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/business-fields', [
            'name' => '  صيدليات  ',
        ]);

        // Assert
        $response->assertCreated()->assertJsonPath('data.name', 'صيدليات');
    }

    public function test_renaming_a_field_reaches_the_shops_already_in_it(): void
    {
        // Arrange — this is a label, not a snapshot of what a shop sold on a given day.
        $field = BusinessField::factory()->named('ملابس')->create();
        $customer = Customer::factory()->create();
        $shop = $customer->shops()->create([
            'name' => 'محل الأناقة',
            'city_id' => City::factory()->create()->id,
            'business_field_id' => $field->id,
        ]);

        // Act
        $response = $this->withHeaders($this->manager())
            ->putJson("/api/v1/business-fields/{$field->id}", ['name' => 'بيع ملابس']);

        // Assert
        $response->assertOk()->assertJsonPath('data.name', 'بيع ملابس');
        $this->assertSame('بيع ملابس', $shop->fresh()->businessField->name);
    }

    public function test_a_field_can_be_stopped_without_touching_the_shops_in_it(): void
    {
        // Arrange
        $field = BusinessField::factory()->named('شحن وتوصيل')->create();
        $customer = Customer::factory()->create();
        $shop = $customer->shops()->create([
            'name' => 'محل الشحن',
            'city_id' => City::factory()->create()->id,
            'business_field_id' => $field->id,
        ]);

        // Act
        $response = $this->withHeaders($this->manager())
            ->patchJson("/api/v1/business-fields/{$field->id}/activation", ['is_active' => false]);

        // Assert — it leaves the pickers; it does not retract what was recorded.
        $response->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame($field->id, $shop->fresh()->business_field_id);
    }

    public function test_writing_needs_the_manage_permission(): void
    {
        // Arrange — a viewer may read the list and nothing more.
        $field = BusinessField::factory()->create();
        $headers = $this->viewer();

        // Act
        $create = $this->withHeaders($headers)->postJson('/api/v1/business-fields', ['name' => 'مجال جديد']);
        $update = $this->withHeaders($headers)->putJson("/api/v1/business-fields/{$field->id}", ['name' => 'اسم آخر']);
        $delete = $this->withHeaders($headers)->deleteJson("/api/v1/business-fields/{$field->id}");

        // Assert
        $create->assertForbidden();
        $update->assertForbidden();
        $delete->assertForbidden();
    }

    // ─────────────────────────── deleting ───────────────────────────

    public function test_an_unused_field_can_be_deleted(): void
    {
        // Arrange — the row that should never have existed: a typo, a duplicate.
        $field = BusinessField::factory()->named('مجل ملابس')->create();

        // Act
        $response = $this->withHeaders($this->manager())->deleteJson("/api/v1/business-fields/{$field->id}");

        // Assert — soft, like every delete here: gone from the API, kept in the table.
        $response->assertOk();
        $this->assertSoftDeleted('business_fields', ['id' => $field->id]);
    }

    public function test_a_field_that_shops_are_recorded_under_is_not_deletable(): void
    {
        // Arrange
        $field = BusinessField::factory()->named('شحن وتوصيل')->create();
        $customer = Customer::factory()->create();
        $customer->shops()->create([
            'name' => 'محل الشحن',
            'city_id' => City::factory()->create()->id,
            'business_field_id' => $field->id,
        ]);

        // Act
        $response = $this->withHeaders($this->manager())->deleteJson("/api/v1/business-fields/{$field->id}");

        // Assert — refusing is the rule: a soft delete would leave the shop pointing at a row
        // the API no longer returns. The message says what to do instead.
        $response->assertStatus(422);
        $this->assertStringContainsString('أوقفه', (string) $response->json('message'));
        $this->assertDatabaseHas('business_fields', ['id' => $field->id, 'deleted_at' => null]);
    }

    public function test_a_deleted_field_releases_its_name(): void
    {
        // Arrange
        $field = BusinessField::factory()->named('صيدليات')->create();
        $this->withHeaders($this->manager())->deleteJson("/api/v1/business-fields/{$field->id}");

        // Act
        $response = $this->withHeaders($this->manager())
            ->postJson('/api/v1/business-fields', ['name' => 'صيدليات']);

        // Assert — the unique index is partial for exactly this reason.
        $response->assertCreated();
    }

    // ─────────────────────────── the history ───────────────────────────

    public function test_the_history_says_who_changed_the_list(): void
    {
        // Arrange — one user throughout, because the causer this asserts on is whoever made the
        // write, and swapping identities between requests inside one test is not what a client
        // does anyway.
        $headers = $this->auth(
            PermissionName::ViewBusinessFields,
            PermissionName::ManageBusinessFields,
            PermissionName::ViewActivityLogs,
        );
        $created = $this->withHeaders($headers)->postJson('/api/v1/business-fields', ['name' => 'صيدليات']);
        $id = $created->json('data.id');
        $this->withHeaders($headers)->putJson("/api/v1/business-fields/{$id}", ['name' => 'صيدليات ومستلزمات طبية']);

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/business-fields/{$id}/logs");

        // Assert — every model here keeps a trail, and this one has a screen of its own to read
        // it on: the create, the rename, and a name against each.
        $response->assertOk();
        $events = array_column($response->json('data'), 'event');
        $this->assertContains('created', $events);
        $this->assertContains('updated', $events);
        $this->assertNotNull($response->json('data.0.causer.name'));
    }
}
