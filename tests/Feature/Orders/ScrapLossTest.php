<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Bags spoiled during production: a real stock loss, FIFO-costed, and a real entry on the
 * order's own cost ledger — but never a rewrite of what the line's own fulfillment already cost.
 *
 * Arrange - Act - Assert throughout.
 */
class ScrapLossTest extends TestCase
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
    private function foreman(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewOrders->value,
            PermissionName::ManageOrders->value,
            PermissionName::MoveOrderToReadyToPrint->value,
            PermissionName::MoveOrderToPrinting->value,
            PermissionName::MoveOrderToReady->value,
            PermissionName::ViewInventory->value,
            PermissionName::ManageInventory->value,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function balanceOf(Warehouse $warehouse, ProductVariant $variant): string
    {
        return (string) (WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $variant->stock_item_id)
            ->first()?->quantity ?? '0.000');
    }

    /**
     * An order that has actually reached «جاهزة» — real stock deducted for real, from a real
     * warehouse — which is where `fulfillment_warehouse_id` is now set, and so where there is
     * anything at all for a scrap loss to draw from. See {@see RecordScrapLoss}.
     */
    private function readyOrder(array $headers): array
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $warehouse = Warehouse::factory()->create();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->stock_item_id, 'to_warehouse_id' => $warehouse->id,
            'quantity' => 100, 'unit_cost' => 4,
        ])->assertCreated();

        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => '40',
        ]);

        // The warehouse is named at the handover now — that is where the stock actually leaves —
        // and «جاهزة» no longer offers the picker, because by then there is nothing left to
        // choose: a correction goes back to the shelf it came off.
        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::ReadyToPrint->value,
            'fields' => ['warehouse_id' => $warehouse->id],
        ])->assertOk();

        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Printing->value,
        ])->assertOk();

        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Ready->value,
        ])->assertOk();

        return [$order->refresh(), $item->refresh(), $warehouse, $variant];
    }

    public function test_scrap_draws_stock_and_records_its_fifo_cost(): void
    {
        // Arrange — 40 drawn on entering ready, 60 left at 4 each
        $headers = $this->foreman();
        [$order, $item, $warehouse, $variant] = $this->readyOrder($headers);
        $this->assertSame('60.000', $this->balanceOf($warehouse, $variant));

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/items/{$item->id}/scrap",
            ['quantity' => 10, 'notes' => 'انحراف في طباعة الشعار'],
        );

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.cost_type', 'scrap_loss')
            ->assertJsonPath('data.amount', '40.00')   // 10 @ 4
            ->assertJsonPath('data.rate', null);

        $this->assertSame('50.000', $this->balanceOf($warehouse, $variant)); // 60 - 10
        $this->assertDatabaseHas('production_cost_entries', [
            'order_id' => $order->id, 'order_item_id' => $item->id,
            'cost_type' => 'scrap_loss', 'amount' => '40.00',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => 'scrap_loss', 'from_warehouse_id' => $warehouse->id,
            'to_warehouse_id' => null, 'quantity' => '10.000', 'reference_id' => $order->id,
        ]);
    }

    public function test_scrap_does_not_rewrite_the_lines_own_material_cost(): void
    {
        // Arrange
        $headers = $this->foreman();
        [$order, $item] = $this->readyOrder($headers);
        $originalMaterialCost = $item->material_cost;
        $originalCogs = $item->cogs;

        // Act
        $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/items/{$item->id}/scrap",
            ['quantity' => 5, 'notes' => 'كيس ممزق'],
        )->assertCreated();

        // Assert — the original fulfillment's own cost is untouched
        $item->refresh();
        $this->assertSame((string) $originalMaterialCost, (string) $item->material_cost);
        $this->assertSame((string) $originalCogs, (string) $item->cogs);
    }

    public function test_scrap_is_refused_before_the_order_has_reached_ready(): void
    {
        // Arrange — `fulfillment_warehouse_id` is only set on entering «جاهزة» now, so an order
        // still in `printing` has just as little to draw a scrap loss from as one still designing.
        $headers = $this->foreman();
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        $item = OrderItem::factory()->for($order)->create();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/items/{$item->id}/scrap",
            ['quantity' => 1, 'notes' => 'تجربة'],
        );

        // Assert
        $response->assertStatus(422);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('production_cost_entries', 0);
    }

    public function test_a_scrap_quantity_that_exceeds_the_shelf_is_refused(): void
    {
        // Arrange
        $headers = $this->foreman();
        [$order, $item, $warehouse, $variant] = $this->readyOrder($headers);

        // Act — more than the 60 remaining
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/items/{$item->id}/scrap",
            ['quantity' => 1000, 'notes' => 'تجربة'],
        );

        // Assert
        $response->assertStatus(422);
        $this->assertSame('60.000', $this->balanceOf($warehouse, $variant));
    }

    public function test_an_item_belonging_to_another_order_is_not_found(): void
    {
        // Arrange
        $headers = $this->foreman();
        [$order] = $this->readyOrder($headers);
        $otherOrder = Order::factory()->create();
        $foreignItem = OrderItem::factory()->for($otherOrder)->create();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/items/{$foreignItem->id}/scrap",
            ['quantity' => 1, 'notes' => 'تجربة'],
        );

        // Assert
        $response->assertNotFound();
    }

    public function test_recording_scrap_needs_the_inventory_manage_permission(): void
    {
        // Arrange
        $headers = $this->foreman();
        [$order, $item] = $this->readyOrder($headers);
        $user = User::factory()->create();
        $user->givePermissionTo([PermissionName::ViewOrders->value, PermissionName::ManageOrders->value]);
        $viewerHeaders = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

        // The container is reused within one test, so the auth guard would otherwise keep
        // resolving the foreman from the setup requests above.
        $this->app->get('auth')->forgetGuards();

        // Act
        $response = $this->withHeaders($viewerHeaders)->postJson(
            "/api/v1/orders/{$order->id}/items/{$item->id}/scrap",
            ['quantity' => 1, 'notes' => 'تجربة'],
        );

        // Assert
        $response->assertForbidden();
    }

    public function test_notes_are_required(): void
    {
        // Arrange
        $headers = $this->foreman();
        [$order, $item] = $this->readyOrder($headers);

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/items/{$item->id}/scrap",
            ['quantity' => 1],
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('notes');
    }
}
