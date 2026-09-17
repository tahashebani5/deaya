<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The invariant the whole context exists to hold:
 *
 * > **for every (warehouse, size), the balance equals the signed sum of its movements.**
 *
 * Everything else — the row lock, the single write path, `quantity` being unfillable, the absence
 * of any endpoint that sets a number — is machinery in service of this one sentence. So it is
 * asserted directly, over state built entirely through the API rather than through factories,
 * because a factory writing a balance would be assuming exactly what is under test.
 *
 * The audit trail is checked here too, for the same reason: a movement that changed a shelf
 * without leaving a trace is the failure this design was chosen to prevent.
 *
 * Arrange - Act - Assert throughout.
 */
class StockLedgerTest extends TestCase
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

    /**
     * The signed sum of everything the ledger says about one shelf: what arrived here, less what
     * left here. This is the number the balance must equal.
     */
    private function ledgerTotalFor(Warehouse $warehouse, StockItem $variant): string
    {
        $in = StockMovement::query()
            ->where('to_warehouse_id', $warehouse->id)
            ->where('stock_item_id', $variant->id)
            ->sum('quantity');

        $out = StockMovement::query()
            ->where('from_warehouse_id', $warehouse->id)
            ->where('stock_item_id', $variant->id)
            ->sum('quantity');

        return bcsub((string) $in, (string) $out, 3);
    }

    private function balanceOf(Warehouse $warehouse, StockItem $variant): string
    {
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $variant->id)
            ->first();

        return (string) ($stock?->quantity ?? '0.000');
    }

    private function assertLedgerReconciles(Warehouse $warehouse, StockItem $variant): void
    {
        $this->assertSame(
            $this->ledgerTotalFor($warehouse, $variant),
            $this->balanceOf($warehouse, $variant),
            'The balance no longer equals the signed sum of the movements that produced it.',
        );
    }

    public function test_a_mixed_sequence_of_movements_leaves_every_balance_reconciled(): void
    {
        // Arrange
        $main = Warehouse::factory()->main()->create();
        $floor = Warehouse::factory()->create();
        $variant = StockItem::factory()->create();
        $headers = $this->manager();

        // Act — a plausible week, entirely through the API
        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $main->id,
            'quantity' => 5000,
        ])->assertCreated();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/transfers', [
            'stock_item_id' => $variant->id,
            'from_warehouse_id' => $main->id,
            'to_warehouse_id' => $floor->id,
            'quantity' => 1200,
        ])->assertCreated();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/fulfillments', [
            'stock_item_id' => $variant->id,
            'from_warehouse_id' => $floor->id,
            'quantity' => 800,
        ])->assertCreated();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/adjustments', [
            'stock_item_id' => $variant->id,
            'warehouse_id' => $floor->id,
            'direction' => 'decrease',
            'quantity' => 50,
            'adjustment_reason' => 'damage',
            'notes' => 'تلف أثناء التشغيل',
        ])->assertCreated();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/adjustments', [
            'stock_item_id' => $variant->id,
            'warehouse_id' => $main->id,
            'direction' => 'increase',
            'quantity' => 25,
            'unit_cost' => 10,
            'notes' => 'جرد — وجد أكثر من المسجل',
        ])->assertCreated();

        // Assert
        $this->assertLedgerReconciles($main, $variant);
        $this->assertLedgerReconciles($floor, $variant);

        // And the arithmetic itself, stated plainly so a failure says which side is wrong
        $this->assertSame('3825.000', $this->balanceOf($main, $variant));   // 5000 - 1200 + 25
        $this->assertSame('350.000', $this->balanceOf($floor, $variant));   // 1200 - 800 - 50
    }

    public function test_a_refused_movement_leaves_no_trace_at_all(): void
    {
        // Arrange — the transaction is the point: a partial failure would leave a shelf count
        // with no reason behind it
        $from = Warehouse::factory()->create();
        $to = Warehouse::factory()->create();
        $variant = StockItem::factory()->create();
        $headers = $this->manager();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $from->id,
            'quantity' => 100,
        ])->assertCreated();

        // Act — more than the source holds
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/transfers', [
            'stock_item_id' => $variant->id,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'quantity' => 500,
        ]);

        // Assert — the refusal wrote nothing at either end, and the ledger still reconciles
        $response->assertStatus(422);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('100.000', $this->balanceOf($from, $variant));
        $this->assertSame('0.000', $this->balanceOf($to, $variant));
        $this->assertLedgerReconciles($from, $variant);
        $this->assertLedgerReconciles($to, $variant);
    }

    public function test_two_sizes_in_one_warehouse_keep_separate_balances(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $small = StockItem::factory()->size(20, 30)->create();
        $large = StockItem::factory()->size(40, 50)->create();
        $headers = $this->manager();

        // Act
        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $small->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 300,
        ])->assertCreated();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $large->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 700,
        ])->assertCreated();

        // Assert
        $this->assertSame('300.000', $this->balanceOf($warehouse, $small));
        $this->assertSame('700.000', $this->balanceOf($warehouse, $large));
        $this->assertDatabaseCount('warehouse_stocks', 2);
    }

    public function test_one_shelf_never_grows_a_second_balance_row(): void
    {
        // Arrange — the unique index is what makes the lock-and-increment correct
        $warehouse = Warehouse::factory()->create();
        $variant = StockItem::factory()->create();
        $headers = $this->manager();

        // Act
        foreach (range(1, 4) as $i) {
            $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
                'stock_item_id' => $variant->id,
                'to_warehouse_id' => $warehouse->id,
                'quantity' => 10,
            ])->assertCreated();
        }

        // Assert
        $this->assertDatabaseCount('warehouse_stocks', 1);
        $this->assertSame('40.000', $this->balanceOf($warehouse, $variant));
    }

    public function test_the_database_refuses_a_negative_balance_even_when_the_action_is_bypassed(): void
    {
        // Arrange — the domain gives the readable 422; this is the guarantee underneath it, for
        // the day some future caller writes the column without going through ApplyStockChange
        $stock = WarehouseStock::factory()->quantity('5.000')->create();

        // Act — wrapped in a nested transaction, which on an open one is a SAVEPOINT.
        //
        // PostgreSQL aborts the *whole* transaction when a statement fails: every query after it
        // is refused with 25P02 until a rollback. RefreshDatabase runs each test inside one
        // transaction, so without the savepoint the violation below would poison it and the
        // assertion underneath — the half that proves the shelf survived — could not run at all.
        // The savepoint rolls back only the failed write, and the exception is rethrown intact.
        $write = fn () => DB::transaction(
            fn () => DB::table('warehouse_stocks')
                ->where('id', $stock->id)
                ->update(['quantity' => '-1.000']),
        );

        // Assert — a loud constraint violation, not a silently negative shelf
        $this->assertThrows($write, QueryException::class);
        $this->assertDatabaseHas('warehouse_stocks', ['id' => $stock->id, 'quantity' => '5.000']);
    }

    public function test_the_database_refuses_a_movement_of_nothing(): void
    {
        // Arrange — a zero row would sit in the ledger looking like an explanation for a balance
        // that never changed
        $movement = StockMovement::factory()->create();

        // Act
        $write = fn () => DB::table('stock_movements')
            ->where('id', $movement->id)
            ->update(['quantity' => '0.000']);

        // Assert
        $this->assertThrows($write, QueryException::class);
    }

    public function test_every_movement_is_recorded_in_the_audit_trail(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = StockItem::factory()->create();
        $headers = $this->manager();

        // Act
        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 10,
        ])->assertCreated();

        // Assert — the movement and the balance line it created both leave a trail, under the
        // stable morph aliases rather than PHP class names
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => 'stock_movement',
            'event' => 'created',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => 'warehouse_stock',
            'event' => 'created',
        ]);
    }

    public function test_the_movements_causer_is_the_signed_in_user(): void
    {
        // Arrange
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewInventory->value,
            PermissionName::ManageInventory->value,
        ]);
        $warehouse = Warehouse::factory()->create();
        $variant = StockItem::factory()->create();

        // Act
        $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken])
            ->postJson('/api/v1/stock-movements/arrivals', [
                'stock_item_id' => $variant->id,
                'to_warehouse_id' => $warehouse->id,
                'quantity' => 10,
            ])->assertCreated();

        // Assert
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => 'stock_movement',
            'causer_type' => 'user',
            'causer_id' => $user->id,
        ]);
    }

    public function test_a_balance_snapshots_its_unit_on_first_arrival(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        // The unit is the shelf's own now, not reached through a product — which is what stops
        // two products sharing this pile from disagreeing about how it is counted.
        $variant = StockItem::factory()->weighed()->create();
        $headers = $this->manager();

        // Act
        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 12.5,
        ])->assertCreated();

        // Assert
        $this->assertDatabaseHas('warehouse_stocks', [
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $variant->id,
            'unit' => 'kilogram',
        ]);
    }

    public function test_a_movement_is_refused_once_the_items_unit_no_longer_matches_the_balance(): void
    {
        // Arrange — a balance already exists in pieces
        $warehouse = Warehouse::factory()->create();
        $variant = StockItem::factory()->create();
        $headers = $this->manager();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 10,
        ])->assertCreated();

        // The shelf's unit is switched to kilograms behind the balance's back — written straight
        // to the column rather than through SetStockItemUnit, precisely because that action would
        // restamp the balance too and there would be nothing left to catch. This is the drift
        // guard for the day some future caller writes the column without going through it.
        $variant->forceFill(['unit' => PricingUnit::Kilogram])->save();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 5,
        ]);

        // Assert — refused rather than silently mixing units, and the balance is untouched
        $response->assertStatus(422)->assertJsonPath('status', false);
        $this->assertSame('10.000', $this->balanceOf($warehouse, $variant));
    }
}
