<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Enums\WarehouseType;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Warehouses — the places stock is held.
 *
 * Two permissions guard them: `inventory.view` to read the list, because anyone taking an order
 * needs to know where stock is, and `inventory.manage` to change it. Both are declared on the
 * routes, so these tests exercise the guard exactly as a client meets it.
 *
 * Arrange - Act - Assert throughout.
 */
class WarehouseTest extends TestCase
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
     * A user holding exactly the permissions named, granted directly rather than through a role.
     *
     * @return array<string, string>
     */
    private function auth(PermissionName ...$permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (PermissionName $p) => $p->value, $permissions));

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** @return array<string, string> */
    private function viewer(): array
    {
        return $this->auth(PermissionName::ViewInventory);
    }

    /** @return array<string, string> */
    private function manager(): array
    {
        return $this->auth(PermissionName::ViewInventory, PermissionName::ManageInventory);
    }

    /** Authenticated, but granted nothing at all. @return array<string, string> */
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
            'name' => 'المخزن الرئيسي',
            'type' => WarehouseType::Main->value,
            'location' => 'طرابلس',
        ], $overrides);
    }

    // ──────────────────────────────── listing ────────────────────────────────

    public function test_a_user_with_view_permission_can_list_the_warehouses(): void
    {
        // Arrange
        Warehouse::factory()->main()->create(['name' => 'المخزن الرئيسي']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/warehouses');

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.0.name', 'المخزن الرئيسي')
            ->assertJsonPath('data.0.type', 'main')
            ->assertJsonPath('data.0.type_label', 'المخزن الرئيسي')
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [['id', 'name', 'type', 'type_label', 'location', 'stocks_count']],
                'meta' => ['current_page', 'per_page', 'last_page', 'total'],
            ]);
    }

    public function test_listing_the_warehouses_needs_authentication(): void
    {
        // Act
        $response = $this->getJson('/api/v1/warehouses');

        // Assert
        $response->assertUnauthorized()->assertJsonPath('status', false);
    }

    public function test_a_user_granted_nothing_may_not_list_the_warehouses(): void
    {
        // Arrange
        Warehouse::factory()->create();
        $headers = $this->outsider();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/warehouses');

        // Assert
        $response->assertForbidden()->assertJsonPath('status', false);
    }

    public function test_the_list_counts_the_stock_lines_without_loading_them(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        WarehouseStock::factory()->count(3)->create(['warehouse_id' => $warehouse->id]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/warehouses');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.stocks_count', 3)
            ->assertJsonMissingPath('data.0.stocks');
    }

    public function test_the_warehouse_list_is_paginated(): void
    {
        // Arrange
        Warehouse::factory()->count(20)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/warehouses?per_page=5&page=2');

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
        Warehouse::factory()->count(3)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/warehouses?per_page=100000');

        // Assert
        $response->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_a_per_page_of_zero_falls_back_to_one(): void
    {
        // Arrange
        Warehouse::factory()->count(3)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/warehouses?per_page=0');

        // Assert
        $response->assertOk()->assertJsonPath('meta.per_page', 1);
    }

    public function test_the_empty_set_is_an_empty_list_rather_than_an_error(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/warehouses');

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_the_list_can_be_searched_by_name(): void
    {
        // Arrange
        Warehouse::factory()->create(['name' => 'مخزن التشغيل']);
        Warehouse::factory()->create(['name' => 'صالة العرض']);
        $headers = $this->viewer();

        // Act — percent-encoded, as any real client sends a query string
        $response = $this->withHeaders($headers)->getJson('/api/v1/warehouses?search='.urlencode('التشغيل'));

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'مخزن التشغيل');
    }

    public function test_the_list_can_be_searched_by_location(): void
    {
        // Arrange
        Warehouse::factory()->create(['name' => 'مخزن أ', 'location' => 'بنغازي']);
        Warehouse::factory()->create(['name' => 'مخزن ب', 'location' => 'طرابلس']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/warehouses?search='.urlencode('بنغازي'));

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'مخزن أ');
    }

    public function test_the_list_can_be_filtered_by_type(): void
    {
        // Arrange
        Warehouse::factory()->main()->create(['name' => 'الرئيسي']);
        Warehouse::factory()->create(['name' => 'التشغيل']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/warehouses?type=operational');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'التشغيل');
    }

    public function test_a_filter_naming_a_type_that_does_not_exist_returns_everything(): void
    {
        // Arrange
        Warehouse::factory()->count(2)->create();
        $headers = $this->viewer();

        // Act — a nonsense filter must not 500, and must not silently return an empty page
        $response = $this->withHeaders($headers)->getJson('/api/v1/warehouses?type=basement');

        // Assert
        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_soft_deleted_warehouse_is_absent_from_the_list(): void
    {
        // Arrange
        Warehouse::factory()->create(['name' => 'باقٍ']);
        Warehouse::factory()->create(['name' => 'محذوف'])->delete();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/warehouses');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'باقٍ');
    }

    // ──────────────────────────────── reading one ────────────────────────────────

    public function test_a_viewer_can_read_one_warehouse(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create(['name' => 'مخزن التشغيل']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/warehouses/{$warehouse->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.id', $warehouse->id)
            ->assertJsonPath('data.name', 'مخزن التشغيل')
            ->assertJsonPath('data.stocks_count', 0);
    }

    public function test_reading_a_warehouse_that_does_not_exist_is_a_404(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/warehouses/999999');

        // Assert
        $response->assertNotFound()->assertJsonPath('status', false);
    }

    public function test_reading_a_soft_deleted_warehouse_is_a_404(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $warehouse->delete();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/warehouses/{$warehouse->id}");

        // Assert
        $response->assertNotFound();
    }

    // ──────────────────────────────── creating ────────────────────────────────

    public function test_a_manager_can_create_a_warehouse(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/warehouses', $this->payload());

        // Assert
        $response->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'تم إضافة المخزن بنجاح')
            ->assertJsonPath('data.name', 'المخزن الرئيسي')
            ->assertJsonPath('data.type', 'main')
            ->assertJsonPath('data.stocks_count', 0);

        $this->assertDatabaseHas('warehouses', [
            'name' => 'المخزن الرئيسي',
            'type' => 'main',
            'location' => 'طرابلس',
        ]);
    }

    public function test_a_viewer_may_not_create_a_warehouse(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/warehouses', $this->payload());

        // Assert
        $response->assertForbidden()->assertJsonPath('status', false);
        $this->assertDatabaseCount('warehouses', 0);
    }

    public function test_creating_a_warehouse_needs_authentication(): void
    {
        // Act
        $response = $this->postJson('/api/v1/warehouses', $this->payload());

        // Assert
        $response->assertUnauthorized();
    }

    public function test_a_warehouse_starts_with_no_location_when_none_is_given(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson('/api/v1/warehouses', $this->payload(['location' => null]));

        // Assert
        $response->assertCreated()->assertJsonPath('data.location', null);
    }

    public function test_two_warehouses_cannot_share_a_name(): void
    {
        // Arrange
        Warehouse::factory()->create(['name' => 'المخزن الرئيسي']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/warehouses', $this->payload());

        // Assert
        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('errors.name.0', 'اسم المخزن مستخدم مسبقاً');
    }

    public function test_a_deleted_warehouse_releases_its_name(): void
    {
        // Arrange — the unique index is partial, so the 422 must agree with what it allows
        Warehouse::factory()->create(['name' => 'المخزن الرئيسي'])->delete();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/warehouses', $this->payload());

        // Assert
        $response->assertCreated()->assertJsonPath('data.name', 'المخزن الرئيسي');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidWarehousePayloads')]
    public function test_an_invalid_warehouse_is_refused(array $overrides, string $invalidField): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson('/api/v1/warehouses', $this->payload($overrides));

        // Assert
        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonStructure(['status', 'message', 'errors' => [$invalidField]]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidWarehousePayloads(): array
    {
        return [
            'no name' => [['name' => null], 'name'],
            'name too short' => [['name' => 'م'], 'name'],
            'name too long' => [['name' => str_repeat('م', 101)], 'name'],
            'no type' => [['type' => null], 'type'],
            'type is not a case' => [['type' => 'basement'], 'type'],
            'location too long' => [['location' => str_repeat('ط', 256)], 'location'],
        ];
    }

    public function test_a_warehouse_name_at_the_length_boundary_is_accepted(): void
    {
        // Arrange
        $headers = $this->manager();
        $name = str_repeat('م', 100);

        // Act
        $response = $this->withHeaders($headers)
            ->postJson('/api/v1/warehouses', $this->payload(['name' => $name]));

        // Assert
        $response->assertCreated()->assertJsonPath('data.name', $name);
    }

    public function test_a_client_cannot_supply_a_warehouse_id(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act — a server-assigned field must be ignored, never honoured
        $response = $this->withHeaders($headers)
            ->postJson('/api/v1/warehouses', $this->payload(['id' => 4242]));

        // Assert
        $response->assertCreated();
        $this->assertDatabaseMissing('warehouses', ['id' => 4242]);
    }

    // ──────────────────────────────── updating ────────────────────────────────

    public function test_a_manager_can_update_a_warehouse(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create(['name' => 'قديم']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/warehouses/{$warehouse->id}",
            $this->payload(['name' => 'جديد', 'type' => WarehouseType::Main->value]),
        );

        // Assert
        $response->assertOk()
            ->assertJsonPath('message', 'تم تحديث المخزن بنجاح')
            ->assertJsonPath('data.name', 'جديد')
            ->assertJsonPath('data.type', 'main');

        $this->assertDatabaseHas('warehouses', [
            'id' => $warehouse->id,
            'name' => 'جديد',
            'type' => 'main',
        ]);
    }

    public function test_a_warehouse_may_keep_its_own_name_on_update(): void
    {
        // Arrange — the uniqueness rule must ignore the row being edited
        $warehouse = Warehouse::factory()->create(['name' => 'المخزن الرئيسي']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/warehouses/{$warehouse->id}",
            $this->payload(['name' => 'المخزن الرئيسي', 'location' => 'مصراتة']),
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.location', 'مصراتة');
    }

    public function test_an_update_that_omits_the_location_clears_it(): void
    {
        // Arrange — PUT replaces the whole record; "save the form" must not preserve a value
        // the user just cleared
        $warehouse = Warehouse::factory()->create(['location' => 'طرابلس']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/warehouses/{$warehouse->id}", [
            'name' => $warehouse->name,
            'type' => $warehouse->type->value,
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('data.location', null);
        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id, 'location' => null]);
    }

    public function test_updating_a_warehouse_never_touches_the_stock_inside_it(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->main()->create();
        $stock = WarehouseStock::factory()->quantity('500.000')->create(['warehouse_id' => $warehouse->id]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/warehouses/{$warehouse->id}",
            $this->payload(['name' => 'مخزن التشغيل', 'type' => WarehouseType::Operational->value]),
        );

        // Assert — retyping a warehouse says what the place is for; it moves nothing
        $response->assertOk();
        $this->assertDatabaseHas('warehouse_stocks', ['id' => $stock->id, 'quantity' => '500.000']);
    }

    public function test_a_viewer_may_not_update_a_warehouse(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create(['name' => 'قديم']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)
            ->putJson("/api/v1/warehouses/{$warehouse->id}", $this->payload(['name' => 'جديد']));

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id, 'name' => 'قديم']);
    }

    public function test_updating_a_warehouse_that_does_not_exist_is_a_404(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson('/api/v1/warehouses/999999', $this->payload());

        // Assert
        $response->assertNotFound();
    }

    // ──────────────────────────────── deleting ────────────────────────────────

    public function test_a_manager_can_delete_an_empty_warehouse(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/warehouses/{$warehouse->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'تم حذف المخزن بنجاح')
            ->assertJsonPath('data', null);

        $this->assertSoftDeleted('warehouses', ['id' => $warehouse->id]);
    }

    public function test_deleting_a_warehouse_takes_its_zero_balance_lines_with_it(): void
    {
        // Arrange — lines at zero do not block the delete, but they do go with it
        $warehouse = Warehouse::factory()->create();
        $stock = WarehouseStock::factory()->empty()->create(['warehouse_id' => $warehouse->id]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/warehouses/{$warehouse->id}");

        // Assert
        $response->assertOk();
        $this->assertSoftDeleted('warehouses', ['id' => $warehouse->id]);
        $this->assertSoftDeleted('warehouse_stocks', ['id' => $stock->id]);
    }

    public function test_a_warehouse_holding_stock_cannot_be_deleted(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $stock = WarehouseStock::factory()->quantity('12.500')->create(['warehouse_id' => $warehouse->id]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/warehouses/{$warehouse->id}");

        // Assert — refused, and the record it refused over is verified untouched
        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonStructure(['status', 'message', 'errors' => ['warehouse']]);

        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('warehouse_stocks', ['id' => $stock->id, 'deleted_at' => null]);
    }

    public function test_a_viewer_may_not_delete_a_warehouse(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/warehouses/{$warehouse->id}");

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id, 'deleted_at' => null]);
    }

    public function test_deleting_a_warehouse_that_does_not_exist_is_a_404(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->deleteJson('/api/v1/warehouses/999999');

        // Assert
        $response->assertNotFound();
    }

    // ──────────────────────────────── history ────────────────────────────────

    public function test_a_warehouses_history_is_readable_with_the_logs_permission(): void
    {
        // Arrange
        $headers = $this->auth(PermissionName::ViewInventory, PermissionName::ManageInventory, PermissionName::ViewActivityLogs);
        $created = $this->withHeaders($headers)->postJson('/api/v1/warehouses', $this->payload());
        $id = $created->json('data.id');

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/warehouses/{$id}/logs");

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.0.event', 'created')
            ->assertJsonPath('data.0.subject_type', 'warehouse');
    }

    public function test_reading_a_warehouses_history_needs_the_logs_permission_not_the_inventory_one(): void
    {
        // Arrange — someone allowed to run the warehouses is not automatically someone allowed
        // to audit their colleagues
        $warehouse = Warehouse::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/warehouses/{$warehouse->id}/logs");

        // Assert
        $response->assertForbidden();
    }
}
