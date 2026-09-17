<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Catalog\Models\Product;
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
 * The stock ledger — the only way a quantity ever changes.
 *
 * Every write test here asserts *two* things: the row that went into the ledger, and the balance
 * it produced. Asserting only one of them would let the two drift apart, which is the single
 * failure this whole context is built to prevent.
 *
 * Arrange - Act - Assert throughout.
 */
class StockMovementTest extends TestCase
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

    /** The storekeeper, and the account the movements should be attributed to. */
    private function storekeeper(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewInventory->value,
            PermissionName::ManageInventory->value,
        ]);

        return $user;
    }

    /** @return array<string, string> */
    private function tokenFor(User $user): array
    {
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
        return $this->tokenFor($this->storekeeper());
    }

    /** @return array<string, string> */
    private function outsider(): array
    {
        return $this->auth();
    }

    /** A size of a per-piece product — the catalogue's default, and the countable case. */
    private function variant(): StockItem
    {
        return StockItem::factory()->create();
    }

    /** A shelf counted by weight, where fractional quantities are the normal case. */
    private function weighedVariant(): StockItem
    {
        return StockItem::factory()->weighed()->create();
    }

    private function stockOf(Warehouse $warehouse, StockItem $variant): ?WarehouseStock
    {
        return WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $variant->id)
            ->first();
    }

    // ──────────────────────────────── arrivals ────────────────────────────────

    public function test_an_arrival_adds_to_the_receiving_warehouse(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->main()->create();
        $variant = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 1000,
            'notes' => 'توريد من المورد',
        ]);

        // Assert — the ledger row …
        $response->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'تم تسجيل التوريد بنجاح')
            ->assertJsonPath('data.movement_type', 'purchase_arrival')
            ->assertJsonPath('data.movement_type_label', 'توريد')
            ->assertJsonPath('data.quantity', '1000.000')
            ->assertJsonPath('data.from_warehouse_id', null)
            ->assertJsonPath('data.to_warehouse_id', $warehouse->id);

        // … and the balance it produced
        $this->assertDatabaseHas('warehouse_stocks', [
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $variant->id,
            'quantity' => '1000.000',
        ]);
    }

    public function test_the_first_arrival_of_a_size_creates_its_balance_line(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();
        $this->assertDatabaseCount('warehouse_stocks', 0);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 50,
        ]);

        // Assert
        $response->assertCreated();
        $this->assertDatabaseCount('warehouse_stocks', 1);
    }

    public function test_a_second_arrival_adds_to_the_existing_balance(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();
        $payload = [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 300,
        ];
        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', $payload);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', $payload);

        // Assert — one line, not two, and it holds the sum
        $response->assertCreated();
        $this->assertDatabaseCount('warehouse_stocks', 1);
        $this->assertSame('600.000', (string) $this->stockOf($warehouse, $variant)?->quantity);
    }

    public function test_recording_an_arrival_needs_authentication(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();

        // Act
        $response = $this->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 10,
        ]);

        // Assert
        $response->assertUnauthorized();
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_a_viewer_may_not_record_an_arrival(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 10,
        ]);

        // Assert
        $response->assertForbidden()->assertJsonPath('status', false);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    // ──────────────────────────────── transfers ────────────────────────────────

    public function test_a_transfer_moves_stock_between_two_warehouses(): void
    {
        // Arrange
        $from = Warehouse::factory()->main()->create();
        $to = Warehouse::factory()->create();
        $variant = $this->variant();
        WarehouseStock::factory()->quantity('1000.000')->create([
            'warehouse_id' => $from->id,
            'stock_item_id' => $variant->id,
        ]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/transfers', [
            'stock_item_id' => $variant->id,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'quantity' => 250,
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('message', 'تم تسجيل التحويل بنجاح')
            ->assertJsonPath('data.movement_type', 'internal_transfer')
            ->assertJsonPath('data.from_warehouse_id', $from->id)
            ->assertJsonPath('data.to_warehouse_id', $to->id);

        $this->assertSame('750.000', (string) $this->stockOf($from, $variant)?->quantity);
        $this->assertSame('250.000', (string) $this->stockOf($to, $variant)?->quantity);
    }

    public function test_a_transfer_beyond_what_the_source_holds_is_refused(): void
    {
        // Arrange
        $from = Warehouse::factory()->create();
        $to = Warehouse::factory()->create();
        $variant = $this->variant();
        WarehouseStock::factory()->quantity('100.000')->create([
            'warehouse_id' => $from->id,
            'stock_item_id' => $variant->id,
        ]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/transfers', [
            'stock_item_id' => $variant->id,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'quantity' => 101,
        ]);

        // Assert — refused, with both numbers, and nothing moved at either end
        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonStructure(['status', 'message', 'errors' => ['quantity']]);

        $this->assertSame('100.000', (string) $this->stockOf($from, $variant)?->quantity);
        $this->assertNull($this->stockOf($to, $variant));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_a_transfer_of_exactly_what_is_there_empties_the_shelf(): void
    {
        // Arrange — the boundary: available == requested must pass
        $from = Warehouse::factory()->create();
        $to = Warehouse::factory()->create();
        $variant = $this->variant();
        WarehouseStock::factory()->quantity('100.000')->create([
            'warehouse_id' => $from->id,
            'stock_item_id' => $variant->id,
        ]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/transfers', [
            'stock_item_id' => $variant->id,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'quantity' => 100,
        ]);

        // Assert
        $response->assertCreated();
        $this->assertSame('0.000', (string) $this->stockOf($from, $variant)?->quantity);
        $this->assertSame('100.000', (string) $this->stockOf($to, $variant)?->quantity);
    }

    public function test_a_transfer_from_a_warehouse_to_itself_is_refused(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        WarehouseStock::factory()->quantity('100.000')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $variant->id,
        ]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/transfers', [
            'stock_item_id' => $variant->id,
            'from_warehouse_id' => $warehouse->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 10,
        ]);

        // Assert — a ledger row claiming a move that never happened is worse than a refusal
        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['to_warehouse_id']]);

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('100.000', (string) $this->stockOf($warehouse, $variant)?->quantity);
    }

    public function test_a_transfer_from_a_shelf_that_has_never_held_the_size_is_refused(): void
    {
        // Arrange
        $from = Warehouse::factory()->create();
        $to = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/transfers', [
            'stock_item_id' => $variant->id,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'quantity' => 1,
        ]);

        // Assert — no row is the same answer as a balance of zero
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['quantity']]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    // ──────────────────────────────── fulfillments ────────────────────────────────

    public function test_a_fulfillment_takes_stock_out_of_the_business(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        WarehouseStock::factory()->quantity('500.000')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $variant->id,
        ]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/fulfillments', [
            'stock_item_id' => $variant->id,
            'from_warehouse_id' => $warehouse->id,
            'quantity' => 120,
            'reference_id' => 77,
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('message', 'تم تسجيل الصرف بنجاح')
            ->assertJsonPath('data.movement_type', 'order_fulfillment')
            ->assertJsonPath('data.from_warehouse_id', $warehouse->id)
            ->assertJsonPath('data.to_warehouse_id', null)
            ->assertJsonPath('data.reference_id', 77);

        $this->assertSame('380.000', (string) $this->stockOf($warehouse, $variant)?->quantity);
    }

    public function test_a_fulfillment_beyond_the_balance_is_refused(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        WarehouseStock::factory()->quantity('10.000')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $variant->id,
        ]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/fulfillments', [
            'stock_item_id' => $variant->id,
            'from_warehouse_id' => $warehouse->id,
            'quantity' => 11,
        ]);

        // Assert — a negative shelf is not a smaller problem than a rejected request
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['quantity']]);
        $this->assertSame('10.000', (string) $this->stockOf($warehouse, $variant)?->quantity);
    }

    // ──────────────────────────────── adjustments ────────────────────────────────

    public function test_an_increasing_adjustment_raises_the_balance(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        WarehouseStock::factory()->quantity('100.000')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $variant->id,
        ]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/adjustments', [
            'stock_item_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'direction' => 'increase',
            'quantity' => 15,
            'unit_cost' => 6,
            'notes' => 'جرد — وجد أكثر من المسجل',
        ]);

        // Assert — a direction is translated into the ledger's shape by the domain, not the client
        $response->assertCreated()
            ->assertJsonPath('message', 'تم تسجيل التسوية بنجاح')
            ->assertJsonPath('data.movement_type', 'adjustment')
            ->assertJsonPath('data.from_warehouse_id', null)
            ->assertJsonPath('data.to_warehouse_id', $warehouse->id);

        $this->assertSame('115.000', (string) $this->stockOf($warehouse, $variant)?->quantity);
    }

    public function test_a_decreasing_adjustment_lowers_the_balance(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        WarehouseStock::factory()->quantity('100.000')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $variant->id,
        ]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/adjustments', [
            'stock_item_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'direction' => 'decrease',
            'quantity' => 15,
            'adjustment_reason' => 'damage',
            'notes' => 'تلف أثناء التخزين',
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.from_warehouse_id', $warehouse->id)
            ->assertJsonPath('data.to_warehouse_id', null);

        $this->assertSame('85.000', (string) $this->stockOf($warehouse, $variant)?->quantity);
    }

    public function test_an_adjustment_that_would_take_the_balance_below_zero_is_refused(): void
    {
        // Arrange — a count that says the shelf is negative is a data error, not a smaller number
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        WarehouseStock::factory()->quantity('10.000')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $variant->id,
        ]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/adjustments', [
            'stock_item_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'direction' => 'decrease',
            'quantity' => 11,
            'adjustment_reason' => 'count_correction',
            'notes' => 'جرد',
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['quantity']]);
        $this->assertSame('10.000', (string) $this->stockOf($warehouse, $variant)?->quantity);
    }

    public function test_an_adjustment_must_carry_a_reason(): void
    {
        // Arrange — the other three movements explain themselves; this one does not
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        WarehouseStock::factory()->quantity('100.000')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $variant->id,
        ]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/adjustments', [
            'stock_item_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'direction' => 'decrease',
            'quantity' => 5,
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['notes']]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_an_adjustment_direction_must_be_one_of_the_two_cases(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/adjustments', [
            'stock_item_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'direction' => 'sideways',
            'quantity' => 5,
            'notes' => 'جرد',
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['direction']]);
    }

    // ──────────────────────────────── quantity rules ────────────────────────────────

    public function test_a_fractional_quantity_of_a_per_piece_product_is_refused(): void
    {
        // Arrange — the same rule that stops an order for half a bag
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 2.5,
        ]);

        // Assert
        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonStructure(['errors' => ['quantity']]);

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('warehouse_stocks', 0);
    }

    public function test_a_fractional_quantity_of_a_per_kilo_product_is_allowed(): void
    {
        // Arrange — a weight is fractional by nature
        $warehouse = Warehouse::factory()->create();
        $variant = $this->weighedVariant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 12.75,
        ]);

        // Assert
        $response->assertCreated()->assertJsonPath('data.quantity', '12.750');
        $this->assertSame('12.750', (string) $this->stockOf($warehouse, $variant)?->quantity);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidArrivalPayloads')]
    public function test_an_invalid_arrival_is_refused(array $overrides, string $invalidField): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();
        $payload = array_merge([
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 10,
        ], $overrides);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', $payload);

        // Assert
        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonStructure(['status', 'message', 'errors' => [$invalidField]]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidArrivalPayloads(): array
    {
        return [
            'no variant' => [['stock_item_id' => null], 'stock_item_id'],
            'variant does not exist' => [['stock_item_id' => 999999], 'stock_item_id'],
            'no warehouse' => [['to_warehouse_id' => null], 'to_warehouse_id'],
            'warehouse does not exist' => [['to_warehouse_id' => 999999], 'to_warehouse_id'],
            'no quantity' => [['quantity' => null], 'quantity'],
            'quantity of zero' => [['quantity' => 0], 'quantity'],
            'negative quantity' => [['quantity' => -5], 'quantity'],
            'quantity is not a number' => [['quantity' => 'كثير'], 'quantity'],
            'notes too long' => [['notes' => str_repeat('ط', 1001)], 'notes'],
        ];
    }

    public function test_a_movement_naming_a_deleted_warehouse_is_refused(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $warehouse->delete();
        $variant = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 10,
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['to_warehouse_id']]);
    }

    // ──────────────────────────────── who moved it ────────────────────────────────

    public function test_the_employee_is_taken_from_the_authenticated_user(): void
    {
        // Arrange
        $storekeeper = $this->storekeeper();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();

        // Act
        $response = $this->withHeaders($this->tokenFor($storekeeper))
            ->postJson('/api/v1/stock-movements/arrivals', [
                'stock_item_id' => $variant->id,
                'to_warehouse_id' => $warehouse->id,
                'quantity' => 10,
            ]);

        // Assert
        $response->assertCreated()->assertJsonPath('data.employee_id', $storekeeper->id);
        $this->assertDatabaseHas('stock_movements', ['employee_id' => $storekeeper->id]);
    }

    public function test_an_employee_id_in_the_body_is_ignored(): void
    {
        // Arrange — a movement cannot be attributed to a colleague
        $storekeeper = $this->storekeeper();
        $someoneElse = User::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();

        // Act
        $response = $this->withHeaders($this->tokenFor($storekeeper))
            ->postJson('/api/v1/stock-movements/arrivals', [
                'stock_item_id' => $variant->id,
                'to_warehouse_id' => $warehouse->id,
                'quantity' => 10,
                'employee_id' => $someoneElse->id,
            ]);

        // Assert
        $response->assertCreated()->assertJsonPath('data.employee_id', $storekeeper->id);
        $this->assertDatabaseMissing('stock_movements', ['employee_id' => $someoneElse->id]);
    }

    public function test_a_movement_type_smuggled_into_the_body_is_ignored(): void
    {
        // Arrange — the endpoint decides the type, not the payload
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 10,
            'movement_type' => 'order_fulfillment',
        ]);

        // Assert
        $response->assertCreated()->assertJsonPath('data.movement_type', 'purchase_arrival');
    }

    // ──────────────────────────────── reading the ledger ────────────────────────────────

    public function test_a_viewer_can_read_the_ledger(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $this->withHeaders($this->manager())->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 40,
        ]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/stock-movements');

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quantity', '40.000')
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [[
                    'id', 'movement_type', 'movement_type_label', 'quantity',
                    'stock_item_id', 'from_warehouse_id', 'to_warehouse_id',
                    'reference_id', 'employee_id', 'notes', 'created_at',
                ]],
                'meta' => ['current_page', 'per_page', 'last_page', 'total'],
            ]);
    }

    public function test_a_ledger_row_says_what_its_quantity_is_counted_in(): void
    {
        // Arrange — a shelf counted by weight, where «1.6» on its own could be anything
        $warehouse = Warehouse::factory()->create();
        $variant = $this->weighedVariant();
        $this->withHeaders($this->manager())->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 1.6,
        ]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/stock-movements');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.quantity', '1.600')
            ->assertJsonPath('data.0.unit', 'kilogram')
            ->assertJsonPath('data.0.unit_label', 'كجم');
    }

    public function test_reading_the_ledger_needs_authentication(): void
    {
        // Act
        $response = $this->getJson('/api/v1/stock-movements');

        // Assert
        $response->assertUnauthorized();
    }

    public function test_a_user_granted_nothing_may_not_read_the_ledger(): void
    {
        // Arrange
        $headers = $this->outsider();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/stock-movements');

        // Assert
        $response->assertForbidden();
    }

    public function test_the_warehouse_filter_matches_either_end(): void
    {
        // Arrange — "everything that happened at the main store" means arrivals and despatches
        $hub = Warehouse::factory()->main()->create();
        $other = Warehouse::factory()->create();
        $variant = $this->variant();
        $manager = $this->manager();

        $this->withHeaders($manager)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $hub->id,
            'quantity' => 100,
        ]);
        $this->withHeaders($manager)->postJson('/api/v1/stock-movements/transfers', [
            'stock_item_id' => $variant->id,
            'from_warehouse_id' => $hub->id,
            'to_warehouse_id' => $other->id,
            'quantity' => 30,
        ]);

        // Act
        $response = $this->withHeaders($manager)->getJson("/api/v1/stock-movements?warehouse_id={$hub->id}");

        // Assert — both the arrival into it and the transfer out of it
        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_ledger_can_be_filtered_by_movement_type(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $manager = $this->manager();

        $this->withHeaders($manager)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 100,
        ]);
        $this->withHeaders($manager)->postJson('/api/v1/stock-movements/fulfillments', [
            'stock_item_id' => $variant->id,
            'from_warehouse_id' => $warehouse->id,
            'quantity' => 10,
        ]);

        // Act
        $response = $this->withHeaders($manager)
            ->getJson('/api/v1/stock-movements?movement_type=order_fulfillment');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.movement_type', 'order_fulfillment');
    }

    public function test_the_ledger_is_newest_first(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $manager = $this->manager();

        $this->withHeaders($manager)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 11,
        ]);
        $this->withHeaders($manager)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 22,
        ]);

        // Act
        $response = $this->withHeaders($manager)->getJson('/api/v1/stock-movements');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.quantity', '22.000')
            ->assertJsonPath('data.1.quantity', '11.000');
    }

    public function test_the_ledger_is_paginated_and_clamps_an_absurd_per_page(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $manager = $this->manager();

        foreach (range(1, 5) as $i) {
            $this->withHeaders($manager)->postJson('/api/v1/stock-movements/arrivals', [
                'stock_item_id' => $variant->id,
                'to_warehouse_id' => $warehouse->id,
                'quantity' => $i,
            ]);
        }

        // Act
        $response = $this->withHeaders($manager)->getJson('/api/v1/stock-movements?per_page=100000');

        // Assert
        $response->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.total', 5);
    }

    public function test_the_empty_ledger_is_an_empty_list(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/stock-movements');

        // Assert
        $response->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    public function test_a_movement_carries_no_updated_at(): void
    {
        // Arrange — a ledger entry is never updated, so publishing the field would invite a
        // client to believe it could be
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 10,
        ]);

        // Assert
        $response->assertCreated()->assertJsonMissingPath('data.updated_at');
    }
}
