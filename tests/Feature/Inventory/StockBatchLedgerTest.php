<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBatchConsumption;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The invariant this table exists to hold, one layer under `StockLedgerTest`'s own:
 *
 * > **for every (warehouse, size), `SUM(stock_batches.quantity_remaining)` equals
 * > `warehouse_stocks.quantity`.**
 *
 * Built the same way `StockLedgerTest` is: state through the real API, because a factory writing
 * a batch would assume exactly what is under test.
 *
 * Arrange - Act - Assert throughout.
 */
class StockBatchLedgerTest extends TestCase
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
    private function manager(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewInventory->value,
            PermissionName::ManageInventory->value,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function balanceOf(Warehouse $warehouse, StockItem $variant): string
    {
        return (string) (WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $variant->id)
            ->first()?->quantity ?? '0.000');
    }

    private function batchTotalOf(Warehouse $warehouse, StockItem $variant): string
    {
        return (string) number_format((float) StockBatch::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $variant->id)
            ->sum('quantity_remaining'), 3, '.', '');
    }

    private function assertBatchesReconcile(Warehouse $warehouse, StockItem $variant): void
    {
        $this->assertSame(
            $this->balanceOf($warehouse, $variant),
            $this->batchTotalOf($warehouse, $variant),
            'The sum of remaining batch quantity no longer equals the warehouse balance.',
        );
    }

    public function test_a_fulfillment_draws_the_oldest_batch_first(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = StockItem::factory()->create();
        $headers = $this->manager();

        // Act — two arrivals at two different costs, then a fulfillment that spans both
        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 100,
            'unit_cost' => 10,
        ])->assertCreated();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 100,
            'unit_cost' => 20,
        ])->assertCreated();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/fulfillments', [
            'stock_item_id' => $variant->id,
            'from_warehouse_id' => $warehouse->id,
            'quantity' => 150,
        ])->assertCreated();

        // Assert — the first (cheaper) batch is fully drawn, the second only half
        $batches = StockBatch::query()->where('warehouse_id', $warehouse->id)->orderBy('unit_cost')->get();
        $this->assertSame('0.000', (string) $batches[0]->quantity_remaining);
        $this->assertSame('50.000', (string) $batches[1]->quantity_remaining);
        $this->assertSame('50.000', $this->balanceOf($warehouse, $variant)); // 100 + 100 - 150
        $this->assertBatchesReconcile($warehouse, $variant);

        // And the fulfillment's own cost is the FIFO blend: 100@10 + 50@20 = 2000
        $movementId = StockMovement::query()
            ->where('movement_type', 'order_fulfillment')->value('id');
        $cost = StockBatchConsumption::query()
            ->where('stock_movement_id', $movementId)->sum('total_cost');
        $this->assertSame('2000.00', number_format((float) $cost, 2, '.', ''));
    }

    public function test_an_arrival_with_no_cost_opens_a_zero_cost_batch_instead_of_being_refused(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = StockItem::factory()->create();
        $headers = $this->manager();

        // Act
        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 40,
        ])->assertCreated();

        // Assert
        $this->assertDatabaseHas('stock_batches', [
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $variant->id,
            'unit_cost' => '0.000',
            'source_type' => 'purchase_arrival',
        ]);
        $this->assertBatchesReconcile($warehouse, $variant);
    }

    public function test_an_increasing_adjustment_requires_a_unit_cost(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = StockItem::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/adjustments', [
            'stock_item_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'direction' => 'increase',
            'quantity' => 10,
            'notes' => 'جرد — وجد أكثر من المسجل',
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('unit_cost');
        $this->assertDatabaseCount('stock_batches', 0);
    }

    public function test_an_increasing_adjustment_with_a_cost_opens_its_own_batch(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = StockItem::factory()->create();
        $headers = $this->manager();

        // Act
        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/adjustments', [
            'stock_item_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'direction' => 'increase',
            'quantity' => 10,
            'unit_cost' => 7.5,
            'notes' => 'جرد — وجد أكثر من المسجل',
        ])->assertCreated();

        // Assert
        $this->assertDatabaseHas('stock_batches', [
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $variant->id,
            'unit_cost' => '7.500',
            'source_type' => 'adjustment',
        ]);
        $this->assertBatchesReconcile($warehouse, $variant);
    }

    public function test_a_decreasing_adjustment_does_not_require_a_cost_and_fifo_consumes(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = StockItem::factory()->create();
        $headers = $this->manager();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 30,
            'unit_cost' => 5,
        ])->assertCreated();

        // Act
        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/adjustments', [
            'stock_item_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'direction' => 'decrease',
            'quantity' => 5,
            'adjustment_reason' => 'damage',
            'notes' => 'تلف',
        ])->assertCreated();

        // Assert
        $this->assertSame('25.000', $this->balanceOf($warehouse, $variant));
        $this->assertBatchesReconcile($warehouse, $variant);
    }

    public function test_a_transfer_relocates_the_exact_batches_it_drew_from_preserving_their_age_and_cost(): void
    {
        // Arrange
        $main = Warehouse::factory()->main()->create();
        $floor = Warehouse::factory()->create();
        $variant = StockItem::factory()->create();
        $headers = $this->manager();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $main->id,
            'quantity' => 60,
            'unit_cost' => 12,
        ])->assertCreated();

        // Act
        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/transfers', [
            'stock_item_id' => $variant->id,
            'from_warehouse_id' => $main->id,
            'to_warehouse_id' => $floor->id,
            'quantity' => 60,
        ])->assertCreated();

        // Assert — the source batch is fully drawn, and the destination batch keeps the original
        // cost and source type rather than opening a fresh one
        $this->assertSame('0.000', $this->balanceOf($main, $variant));
        $this->assertSame('60.000', $this->balanceOf($floor, $variant));
        $this->assertBatchesReconcile($main, $variant);
        $this->assertBatchesReconcile($floor, $variant);

        $this->assertDatabaseHas('stock_batches', [
            'warehouse_id' => $floor->id,
            'stock_item_id' => $variant->id,
            'unit_cost' => '12.000',
            'source_type' => 'purchase_arrival',
            'quantity_remaining' => '60.000',
        ]);
    }

    public function test_a_transfer_that_spans_two_cost_layers_relocates_both_separately(): void
    {
        // Arrange — two arrivals at two costs, so the transfer must draw from both
        $main = Warehouse::factory()->main()->create();
        $floor = Warehouse::factory()->create();
        $variant = StockItem::factory()->create();
        $headers = $this->manager();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $main->id,
            'quantity' => 40,
            'unit_cost' => 8,
        ])->assertCreated();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $main->id,
            'quantity' => 40,
            'unit_cost' => 9,
        ])->assertCreated();

        // Act — more than the first layer alone holds
        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/transfers', [
            'stock_item_id' => $variant->id,
            'from_warehouse_id' => $main->id,
            'to_warehouse_id' => $floor->id,
            'quantity' => 60,
        ])->assertCreated();

        // Assert — the destination now holds two distinct batches, not one blended one
        $this->assertDatabaseCount('stock_batches', 4); // two originals + two relocated
        $floorBatches = StockBatch::query()->where('warehouse_id', $floor->id)->orderBy('unit_cost')->get();
        $this->assertCount(2, $floorBatches);
        $this->assertSame('8.000', (string) $floorBatches[0]->unit_cost);
        $this->assertSame('40.000', (string) $floorBatches[0]->quantity_remaining);
        $this->assertSame('9.000', (string) $floorBatches[1]->unit_cost);
        $this->assertSame('20.000', (string) $floorBatches[1]->quantity_remaining);
        $this->assertBatchesReconcile($main, $variant);
        $this->assertBatchesReconcile($floor, $variant);
    }

    public function test_insufficient_stock_is_still_refused_before_any_batch_is_touched(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = StockItem::factory()->create();
        $headers = $this->manager();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 10,
            'unit_cost' => 4,
        ])->assertCreated();

        // Act — more than the shelf holds
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/fulfillments', [
            'stock_item_id' => $variant->id,
            'from_warehouse_id' => $warehouse->id,
            'quantity' => 50,
        ]);

        // Assert — refused, and the one existing batch is untouched
        $response->assertStatus(422);
        $this->assertDatabaseHas('stock_batches', [
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $variant->id,
            'quantity_remaining' => '10.000',
        ]);
        $this->assertDatabaseCount('stock_batch_consumptions', 0);
        $this->assertBatchesReconcile($warehouse, $variant);
    }
}
