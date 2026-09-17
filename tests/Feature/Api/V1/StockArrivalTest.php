<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Vendor\Models\StockArrival;
use App\Domain\Vendor\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Stock arrivals — shipments received from a vendor, posted onto the same ledger
 * `stock-movements/arrivals` writes to.
 *
 * The write tests here assert three things together: the document, the ledger row each line
 * produced, and the balance it left behind — asserting only the document would miss the one
 * failure mode this feature exists to prevent, a StockArrival that does not actually move stock.
 *
 * Guarded by `inventory.view`/`inventory.manage`, the same pair warehouses and the ledger share.
 *
 * Arrange - Act - Assert throughout.
 */
class StockArrivalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    /** @return array<string, string> */
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

    private function variant(): StockItem
    {
        return StockItem::factory()->create();
    }

    /** A shelf counted by the piece (the factory default), where a fractional quantity is refused. */
    private function pieceVariant(): StockItem
    {
        return StockItem::factory()->create();
    }

    private function stockOf(Warehouse $warehouse, StockItem $variant): ?WarehouseStock
    {
        return WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $variant->id)
            ->first();
    }

    // ──────────────────────────────── recording ────────────────────────────────

    public function test_a_manager_can_record_a_stock_arrival(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-arrivals', [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'invoice_number' => 'INV-1001',
            'items' => [
                ['stock_item_id' => $variant->id, 'quantity' => 200],
            ],
        ]);

        // Assert — the document …
        $response->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'تم تسجيل التوريد بنجاح')
            ->assertJsonPath('data.vendor_id', $vendor->id)
            ->assertJsonPath('data.warehouse_id', $warehouse->id)
            ->assertJsonPath('data.invoice_number', 'INV-1001')
            ->assertJsonPath('data.items.0.quantity', '200.000')
            // Most arrivals are unplanned — no purchase order behind this one.
            ->assertJsonPath('data.purchase_order_id', null);

        // … the ledger row it produced …
        $arrivalId = $response->json('data.id');
        $this->assertDatabaseHas('stock_movements', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'from_warehouse_id' => null,
            'quantity' => '200.000',
            'movement_type' => 'purchase_arrival',
            'reference_id' => $arrivalId,
        ]);

        // … and the balance it left behind
        $this->assertSame('200.000', (string) $this->stockOf($warehouse, $variant)?->quantity);
    }

    public function test_the_generic_endpoint_ignores_a_purchase_order_id_sent_in_the_payload(): void
    {
        // Arrange — only PurchaseOrder\Actions\ReceivePurchaseOrder may ever set this column;
        // StoreStockArrivalRequest does not declare the field, so Laravel's own validated()
        // already strips it before it reaches the domain.
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-arrivals', [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'purchase_order_id' => 999999,
            'items' => [['stock_item_id' => $variant->id, 'quantity' => 10]],
        ]);

        // Assert
        $response->assertCreated()->assertJsonPath('data.purchase_order_id', null);
        $this->assertDatabaseHas('stock_arrivals', ['vendor_id' => $vendor->id, 'purchase_order_id' => null]);
    }

    public function test_a_stock_arrival_with_several_lines_posts_one_movement_per_line(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variantA = $this->variant();
        $variantB = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-arrivals', [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['stock_item_id' => $variantA->id, 'quantity' => 100],
                ['stock_item_id' => $variantB->id, 'quantity' => 50],
            ],
        ]);

        // Assert
        $response->assertCreated()->assertJsonCount(2, 'data.items');
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertSame('100.000', (string) $this->stockOf($warehouse, $variantA)?->quantity);
        $this->assertSame('50.000', (string) $this->stockOf($warehouse, $variantB)?->quantity);
    }

    public function test_the_received_by_field_is_stamped_from_the_authenticated_user(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $user = User::factory()->create();
        $user->givePermissionTo([PermissionName::ViewInventory->value, PermissionName::ManageInventory->value]);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-arrivals', [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'items' => [['stock_item_id' => $variant->id, 'quantity' => 10]],
        ]);

        // Assert — never accepted from the body, always the caller
        $response->assertCreated()->assertJsonPath('data.received_by', $user->id);
        $this->assertDatabaseHas('stock_arrivals', ['vendor_id' => $vendor->id, 'received_by' => $user->id]);
    }

    public function test_a_fractional_quantity_of_a_per_piece_product_is_refused(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->pieceVariant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-arrivals', [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'items' => [['stock_item_id' => $variant->id, 'quantity' => 2.5]],
        ]);

        // Assert — refused, and nothing partially lands
        $response->assertStatus(422);
        $this->assertDatabaseCount('stock_arrivals', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_recording_a_stock_arrival_needs_authentication(): void
    {
        // Act
        $response = $this->postJson('/api/v1/stock-arrivals', []);

        // Assert
        $response->assertUnauthorized();
    }

    public function test_a_viewer_may_not_record_a_stock_arrival(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-arrivals', [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'items' => [['stock_item_id' => $variant->id, 'quantity' => 10]],
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseCount('stock_arrivals', 0);
    }

    public function test_a_vendor_that_does_not_exist_is_refused(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-arrivals', [
            'vendor_id' => 999999,
            'warehouse_id' => $warehouse->id,
            'items' => [['stock_item_id' => $variant->id, 'quantity' => 10]],
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['vendor_id']]);
    }

    public function test_at_least_one_item_is_required(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-arrivals', [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'items' => [],
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['items']]);
    }

    // ──────────────────────────────── listing & reading ────────────────────────────────

    public function test_a_viewer_can_list_stock_arrivals(): void
    {
        // Arrange
        StockArrival::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/stock-arrivals');

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_listing_can_be_filtered_by_vendor(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        StockArrival::factory()->from($vendor)->create();
        StockArrival::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/stock-arrivals?vendor_id={$vendor->id}");

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.vendor_id', $vendor->id);
    }

    public function test_a_viewer_can_read_one_stock_arrival(): void
    {
        // Arrange
        $arrival = StockArrival::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/stock-arrivals/{$arrival->id}");

        // Assert
        $response->assertOk()->assertJsonPath('data.id', $arrival->id);
    }

    public function test_reading_a_stock_arrival_that_does_not_exist_is_a_404(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/stock-arrivals/999999');

        // Assert
        $response->assertNotFound();
    }

    public function test_listing_stock_arrivals_needs_authentication(): void
    {
        // Act
        $response = $this->getJson('/api/v1/stock-arrivals');

        // Assert
        $response->assertUnauthorized();
    }
}
