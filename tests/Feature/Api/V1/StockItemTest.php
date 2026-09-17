<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Stock items — what a warehouse actually holds.
 *
 * A stock item is a material *at a size*: «كيس شحن 25*35». Many product sizes, across different
 * products, point at one — كيس شحن سادة 25*35 and كيس شحن مطبوع 25*35 are two catalogue rows and
 * one pile of bags. Every balance, ledger row, cost layer and purchase order line is keyed on
 * this, which is why deleting one is guarded twice.
 *
 * Guarded by the same `inventory.view` / `inventory.manage` pair as the warehouses themselves:
 * maintaining the shelves and administering what sits on them are the same job.
 *
 * Arrange - Act - Assert throughout.
 */
class StockItemTest extends TestCase
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

    /** @return array<string, string> */
    private function outsider(): array
    {
        return $this->auth();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'كيس شحن',
            'width_cm' => 25,
            'height_cm' => 35,
            'unit' => PricingUnit::Piece->value,
        ], $overrides);
    }

    // ──────────────────────────────── listing ────────────────────────────────

    public function test_a_viewer_can_list_the_stock_items(): void
    {
        // Arrange
        StockItem::factory()->named('كيس شحن')->size(25, 35)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/stock-items');

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.0.name', 'كيس شحن')
            ->assertJsonPath('data.0.display_name', 'كيس شحن 25*35')
            ->assertJsonPath('data.0.unit', 'piece')
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [[
                    'id', 'code', 'name', 'width_cm', 'height_cm', 'display_name',
                    'unit', 'unit_label', 'description', 'is_active', 'sort_order',
                ]],
                'meta' => ['current_page', 'per_page', 'last_page', 'total'],
            ]);
    }

    public function test_the_list_counts_the_sizes_drawing_on_each_shelf_without_loading_them(): void
    {
        // Arrange — the number that makes the sharing visible: two products, one pile
        $item = StockItem::factory()->create();
        ProductVariant::factory()->drawingFrom($item)->create();
        ProductVariant::factory()->drawingFrom($item)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/stock-items');

        // Assert
        $response->assertOk()->assertJsonPath('data.0.variants_count', 2);
    }

    public function test_listing_the_stock_items_needs_authentication(): void
    {
        // Act
        $response = $this->getJson('/api/v1/stock-items');

        // Assert
        $response->assertUnauthorized();
    }

    public function test_a_user_granted_nothing_may_not_list_the_stock_items(): void
    {
        // Arrange
        $headers = $this->outsider();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/stock-items');

        // Assert
        $response->assertForbidden()->assertJsonPath('status', false);
    }

    public function test_the_list_can_be_searched_by_name(): void
    {
        // Arrange
        StockItem::factory()->named('كيس شحن')->size(25, 35)->create();
        StockItem::factory()->named('كيس ورقي')->size(16, 22)->create();
        $headers = $this->viewer();

        // Act
        // urlencode, as every other Arabic search test here does: a raw multi-byte term in the
        // query string reaches Postgres as an invalid byte sequence and comes back SQLSTATE 22021.
        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/stock-items?search='.urlencode('ورقي'));

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'كيس ورقي');
    }

    public function test_the_list_can_be_narrowed_to_one_size(): void
    {
        // Arrange — what the picker on a product's size asks for: given a 25*35 variant, offer
        // the shelves that are 25*35
        StockItem::factory()->named('كيس شحن')->size(25, 35)->create();
        StockItem::factory()->named('كيس يد')->size(25, 35)->create();
        StockItem::factory()->named('كيس شحن')->size(35, 40)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/stock-items?width_cm=25&height_cm=35');

        // Assert
        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_list_can_be_filtered_by_activity(): void
    {
        // Arrange
        StockItem::factory()->create();
        StockItem::factory()->create(['is_active' => false]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/stock-items?is_active=0');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_active', false);
    }

    public function test_the_list_is_paginated_and_clamps_an_absurd_per_page(): void
    {
        // Arrange
        StockItem::factory()->count(20)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/stock-items?per_page=100000');

        // Assert
        $response->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.total', 20);
    }

    public function test_the_empty_set_is_an_empty_list_rather_than_an_error(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/stock-items');

        // Assert
        $response->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    public function test_a_soft_deleted_stock_item_is_absent_from_the_list(): void
    {
        // Arrange
        $item = StockItem::factory()->create();
        $item->delete();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/stock-items');

        // Assert
        $response->assertOk()->assertJsonCount(0, 'data');
    }

    // ──────────────────────────────── reading one ────────────────────────────────

    public function test_a_viewer_can_read_one_stock_item(): void
    {
        // Arrange
        $item = StockItem::factory()->named('كيس شحن')->size(25, 35)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/stock-items/{$item->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.id', $item->id)
            ->assertJsonPath('data.display_name', 'كيس شحن 25*35');
    }

    public function test_reading_a_stock_item_that_does_not_exist_is_a_404(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/stock-items/999999');

        // Assert
        $response->assertNotFound();
    }

    // ──────────────────────────────── creating ────────────────────────────────

    public function test_a_manager_can_create_a_stock_item(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-items', $this->payload());

        // Assert
        $response->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'تم إضافة المادة بنجاح')
            ->assertJsonPath('data.name', 'كيس شحن')
            ->assertJsonPath('data.display_name', 'كيس شحن 25*35');
        $this->assertDatabaseHas('stock_items', [
            'name' => 'كيس شحن', 'width_cm' => 25, 'height_cm' => 35, 'unit' => 'piece',
        ]);
    }

    public function test_a_new_stock_item_is_given_its_code_by_the_server(): void
    {
        // Arrange — S + the row id, like a product's P and a customer's C
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-items', $this->payload());

        // Assert
        $response->assertCreated();
        $this->assertSame('S'.$response->json('data.id'), $response->json('data.code'));
    }

    public function test_a_client_cannot_supply_a_stock_items_code_or_id(): void
    {
        // Arrange — server-assigned fields are never fillable (RULES.md §9.4)
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            '/api/v1/stock-items',
            $this->payload(['id' => 4242, 'code' => 'S9999']),
        );

        // Assert
        $response->assertCreated();
        $this->assertNotSame(4242, $response->json('data.id'));
        $this->assertNotSame('S9999', $response->json('data.code'));
    }

    public function test_a_stock_item_with_no_size_is_allowed(): void
    {
        // Arrange — a roll, an ink, anything counted without dimensions
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-items', [
            'name' => 'حبر أسود',
            'unit' => PricingUnit::Kilogram->value,
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.display_name', 'حبر أسود')
            ->assertJsonPath('data.width_cm', null);
    }

    public function test_one_material_may_exist_at_several_sizes(): void
    {
        // Arrange — «كيس شحن» is one material and three shelves; nobody should have to invent
        // three names for it
        $headers = $this->manager();
        $this->withHeaders($headers)->postJson('/api/v1/stock-items', $this->payload())->assertCreated();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            '/api/v1/stock-items',
            $this->payload(['width_cm' => 35, 'height_cm' => 40]),
        );

        // Assert
        $response->assertCreated()->assertJsonPath('data.display_name', 'كيس شحن 35*40');
    }

    public function test_the_same_material_at_the_same_size_cannot_be_created_twice(): void
    {
        // Arrange
        $headers = $this->manager();
        $this->withHeaders($headers)->postJson('/api/v1/stock-items', $this->payload())->assertCreated();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-items', $this->payload());

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_two_unsized_items_cannot_share_a_name_either(): void
    {
        // Arrange — the NULL trap: PostgreSQL treats NULLs as distinct in a unique index, so
        // without the COALESCE in the index this pair would both insert
        $headers = $this->manager();
        $body = ['name' => 'حبر أسود', 'unit' => PricingUnit::Kilogram->value];
        $this->withHeaders($headers)->postJson('/api/v1/stock-items', $body)->assertCreated();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-items', $body);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_a_deleted_stock_item_releases_its_name_and_size(): void
    {
        // Arrange — every unique index in this schema is partial
        $item = StockItem::factory()->named('كيس شحن')->size(25, 35)->create();
        $item->delete();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-items', $this->payload());

        // Assert
        $response->assertCreated();
    }

    public function test_half_a_size_is_refused(): void
    {
        // Arrange — a width with no height would produce a shelf nobody could name
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            '/api/v1/stock-items',
            ['name' => 'كيس شحن', 'unit' => PricingUnit::Piece->value, 'width_cm' => 25],
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('height_cm');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidStockItems')]
    public function test_an_invalid_stock_item_is_refused(array $overrides, string $invalidField): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-items', $this->payload($overrides));

        // Assert
        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonValidationErrors($invalidField);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidStockItems(): array
    {
        return [
            'no name' => [['name' => ''], 'name'],
            'name too short' => [['name' => 'ك'], 'name'],
            'name too long' => [['name' => str_repeat('ك', 256)], 'name'],
            'no unit' => [['unit' => ''], 'unit'],
            'unknown unit' => [['unit' => 'metre'], 'unit'],
            'zero width' => [['width_cm' => 0], 'width_cm'],
            'negative height' => [['height_cm' => -5], 'height_cm'],
            'absurd width' => [['width_cm' => 5000], 'width_cm'],
            'negative sort order' => [['sort_order' => -1], 'sort_order'],
        ];
    }

    public function test_a_viewer_may_not_create_a_stock_item(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-items', $this->payload());

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseCount('stock_items', 0);
    }

    public function test_creating_a_stock_item_needs_authentication(): void
    {
        // Act
        $response = $this->postJson('/api/v1/stock-items', $this->payload());

        // Assert
        $response->assertUnauthorized();
    }

    // ──────────────────────────────── updating ────────────────────────────────

    public function test_a_manager_can_update_a_stock_item(): void
    {
        // Arrange
        $item = StockItem::factory()->named('كيس شحن')->size(25, 35)->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/stock-items/{$item->id}", [
            'name' => 'كيس شحن مقوى',
            'width_cm' => 25,
            'height_cm' => 35,
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('data.name', 'كيس شحن مقوى');
        $this->assertDatabaseHas('stock_items', ['id' => $item->id, 'name' => 'كيس شحن مقوى']);
    }

    public function test_a_stock_item_may_keep_its_own_name_on_update(): void
    {
        // Arrange — the uniqueness rule has to ignore this row or every save collides with itself
        $item = StockItem::factory()->named('كيس شحن')->size(25, 35)->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/stock-items/{$item->id}", [
            'name' => 'كيس شحن',
            'width_cm' => 25,
            'height_cm' => 35,
            'sort_order' => 3,
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('data.sort_order', 3);
    }

    public function test_an_update_cannot_change_the_unit(): void
    {
        // Arrange — every balance and cost layer carries a snapshot of it, so the unit moves only
        // through its own endpoint, which restamps them all in one transaction
        $item = StockItem::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/stock-items/{$item->id}", [
            'name' => $item->name,
            'width_cm' => $item->width_cm,
            'height_cm' => $item->height_cm,
            'unit' => PricingUnit::Kilogram->value,
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('data.unit', 'piece');
        $this->assertDatabaseHas('stock_items', ['id' => $item->id, 'unit' => 'piece']);
    }

    public function test_a_viewer_may_not_update_a_stock_item(): void
    {
        // Arrange
        $item = StockItem::factory()->named('كيس شحن')->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/stock-items/{$item->id}", [
            'name' => 'مسروق',
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('stock_items', ['id' => $item->id, 'name' => 'كيس شحن']);
    }

    public function test_updating_a_stock_item_that_does_not_exist_is_a_404(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson('/api/v1/stock-items/999999', $this->payload());

        // Assert
        $response->assertNotFound();
    }

    // ──────────────────────────────── the unit ────────────────────────────────

    public function test_a_manager_can_set_the_unit_and_every_balance_follows(): void
    {
        // Arrange — **the quantity does not come with the unit.** 40 bags are not 40 kilograms,
        // so what was counted in the old unit is discarded rather than relabelled, and it leaves
        // through the ledger as an adjustment so the balance still equals the sum of its
        // movements. The behaviour and its reasoning are pinned in
        // {@see \Tests\Feature\Inventory\SetStockItemUnitTest}; this asserts the endpoint
        // agrees.
        $item = StockItem::factory()->create();
        $warehouse = Warehouse::factory()->create();
        WarehouseStock::factory()->quantity('40.000')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $item->id,
        ]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->patchJson(
            "/api/v1/stock-items/{$item->id}/unit",
            ['unit' => PricingUnit::Kilogram->value],
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.unit', 'kilogram');
        $this->assertDatabaseHas('warehouse_stocks', [
            'stock_item_id' => $item->id, 'unit' => 'kilogram', 'quantity' => '0.000',
        ]);
        $this->assertDatabaseHas('stock_batches', ['stock_item_id' => $item->id, 'unit' => 'kilogram']);
    }

    public function test_setting_an_unknown_unit_is_refused(): void
    {
        // Arrange
        $item = StockItem::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->patchJson(
            "/api/v1/stock-items/{$item->id}/unit",
            ['unit' => 'metre'],
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('unit');
    }

    public function test_a_viewer_may_not_set_the_unit(): void
    {
        // Arrange
        $item = StockItem::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->patchJson(
            "/api/v1/stock-items/{$item->id}/unit",
            ['unit' => PricingUnit::Kilogram->value],
        );

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('stock_items', ['id' => $item->id, 'unit' => 'piece']);
    }

    // ──────────────────────────────── deleting ────────────────────────────────

    public function test_a_manager_can_delete_a_stock_item_nothing_is_using(): void
    {
        // Arrange
        $item = StockItem::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/stock-items/{$item->id}");

        // Assert
        $response->assertOk()->assertJsonPath('message', 'تم حذف المادة بنجاح');
        $this->assertSoftDeleted('stock_items', ['id' => $item->id]);
    }

    public function test_a_stock_item_with_quantity_on_a_shelf_cannot_be_deleted(): void
    {
        // Arrange — stock inside an item the API says does not exist is a balance nobody can
        // reconcile
        $item = StockItem::factory()->create();
        WarehouseStock::factory()->quantity('12.000')->create(['stock_item_id' => $item->id]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/stock-items/{$item->id}");

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['stock_item']]);
        $this->assertDatabaseHas('stock_items', ['id' => $item->id, 'deleted_at' => null]);
    }

    public function test_a_shelf_that_has_been_emptied_can_be_deleted(): void
    {
        // Arrange — a balance of zero is a line left behind, not stock; refusing over it would
        // make the route unusable after the first month
        $item = StockItem::factory()->create();
        WarehouseStock::factory()->empty()->create(['stock_item_id' => $item->id]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/stock-items/{$item->id}");

        // Assert
        $response->assertOk();
        $this->assertSoftDeleted('stock_items', ['id' => $item->id]);
    }

    public function test_a_stock_item_a_product_size_still_draws_from_cannot_be_deleted(): void
    {
        // Arrange — the foreign key is nullOnDelete, so this would not orphan a row; it would do
        // something quieter and worse, cutting the link and surfacing weeks later as an order
        // that cannot be fulfilled
        $item = StockItem::factory()->create();
        ProductVariant::factory()->drawingFrom($item)->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/stock-items/{$item->id}");

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['stock_item']]);
        $this->assertDatabaseHas('stock_items', ['id' => $item->id, 'deleted_at' => null]);
    }

    public function test_a_viewer_may_not_delete_a_stock_item(): void
    {
        // Arrange
        $item = StockItem::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/stock-items/{$item->id}");

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('stock_items', ['id' => $item->id, 'deleted_at' => null]);
    }

    public function test_deleting_a_stock_item_that_does_not_exist_is_a_404(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->deleteJson('/api/v1/stock-items/999999');

        // Assert
        $response->assertNotFound();
    }

    // ──────────────────────────────── its history ────────────────────────────────

    public function test_a_stock_items_history_is_readable_with_the_logs_permission(): void
    {
        // Arrange
        $item = StockItem::factory()->create();
        $headers = $this->auth(PermissionName::ViewActivityLogs);

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/stock-items/{$item->id}/logs");

        // Assert
        $response->assertOk()->assertJsonPath('status', true);
    }

    public function test_reading_a_stock_items_history_needs_the_logs_permission_not_the_inventory_one(): void
    {
        // Arrange — someone allowed to edit the shelves is not automatically someone allowed to
        // audit their colleagues
        $item = StockItem::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/stock-items/{$item->id}/logs");

        // Assert
        $response->assertForbidden();
    }
}
