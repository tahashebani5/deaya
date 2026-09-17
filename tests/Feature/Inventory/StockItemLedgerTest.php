<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * One shelf's history read as a ledger: every row signed for the warehouse it is read from,
 * carrying the balance the shelf stood at once that row had happened, and — for a reader allowed
 * to know — what the stock on that row cost.
 *
 * The invariant is the one a person checks on the screen: **the balance on the newest row is the
 * balance in the header**, whatever filters narrowed the page. Everything is built through the
 * API, because a factory writing a balance would be assuming exactly what is under test.
 *
 * Arrange - Act - Assert throughout.
 */
class StockItemLedgerTest extends TestCase
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
    private function storekeeper(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewInventory->value,
            PermissionName::ManageInventory->value,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** @return array<string, string> */
    private function accountant(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewInventory->value,
            PermissionName::ManageInventory->value,
            PermissionName::ViewStockCost->value,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** @param array<string, string> $headers */
    private function arrive(array $headers, Warehouse $to, StockItem $item, int $quantity, ?float $unitCost = null): void
    {
        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $item->id,
            'to_warehouse_id' => $to->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
        ])->assertCreated();
    }

    /** @param array<string, string> $headers */
    private function issue(array $headers, Warehouse $from, StockItem $item, int $quantity): void
    {
        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/fulfillments', [
            'stock_item_id' => $item->id,
            'from_warehouse_id' => $from->id,
            'quantity' => $quantity,
        ])->assertCreated();
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<int, array<string, mixed>>
     */
    private function ledger(array $headers, Warehouse $warehouse, StockItem $item, array $extra = []): array
    {
        return $this->withHeaders($headers)
            ->getJson('/api/v1/stock-movements?'.http_build_query([
                'warehouse_id' => $warehouse->id,
                'stock_item_id' => $item->id,
            ] + $extra))
            ->assertOk()
            ->json('data');
    }

    public function test_every_row_carries_the_balance_the_shelf_stood_at_after_it(): void
    {
        // Arrange — the sequence from the screenshot that motivated this: in, in, out, count down
        $warehouse = Warehouse::factory()->create();
        $item = StockItem::factory()->create();
        $headers = $this->storekeeper();

        $this->arrive($headers, $warehouse, $item, 1000, 3.5);
        $this->arrive($headers, $warehouse, $item, 300, 3.5);
        $this->issue($headers, $warehouse, $item, 1000);
        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/adjustments', [
            'stock_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'direction' => 'decrease',
            'quantity' => 100,
            'adjustment_reason' => 'count_correction',
            'notes' => 'جرد',
        ])->assertCreated();

        // Act
        $rows = $this->ledger($headers, $warehouse, $item);

        // Assert — newest first, and each balance is the running total up to that row
        $this->assertSame(
            ['-100.000', '-1000.000', '300.000', '1000.000'],
            array_column($rows, 'signed_quantity'),
        );
        $this->assertSame(
            ['200.000', '300.000', '1300.000', '1000.000'],
            array_column($rows, 'balance_after'),
        );

        // …and the newest row's balance is the header's balance, to the last digit.
        $balance = WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $item->id)
            ->value('quantity');
        $this->assertSame((string) $balance, $rows[0]['balance_after']);
    }

    public function test_a_transfer_is_signed_for_the_warehouse_it_is_read_from(): void
    {
        // Arrange
        $main = Warehouse::factory()->create();
        $floor = Warehouse::factory()->create();
        $item = StockItem::factory()->create();
        $headers = $this->storekeeper();

        $this->arrive($headers, $main, $item, 500, 2);
        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/transfers', [
            'stock_item_id' => $item->id,
            'from_warehouse_id' => $main->id,
            'to_warehouse_id' => $floor->id,
            'quantity' => 200,
        ])->assertCreated();

        // Act
        $atMain = $this->ledger($headers, $main, $item);
        $atFloor = $this->ledger($headers, $floor, $item);
        $unscoped = $this->withHeaders($headers)
            ->getJson('/api/v1/stock-movements?stock_item_id='.$item->id)
            ->assertOk()
            ->json('data');

        // Assert — one row, two signs, depending on where it is read; and no sign at all when
        // the reader did not say where they are standing.
        $this->assertSame('-200.000', $atMain[0]['signed_quantity']);
        $this->assertSame('300.000', $atMain[0]['balance_after']);

        $this->assertSame('200.000', $atFloor[0]['signed_quantity']);
        $this->assertSame('200.000', $atFloor[0]['balance_after']);

        $this->assertNull($unscoped[0]['signed_quantity']);
        $this->assertNull($unscoped[0]['balance_after']);
    }

    public function test_a_filter_narrows_the_rows_but_never_the_balance(): void
    {
        // Arrange — two arrivals and one issue; filtering to arrivals hides the issue but the
        // balance on the later arrival must still be what the shelf held then, issue included.
        $warehouse = Warehouse::factory()->create();
        $item = StockItem::factory()->create();
        $headers = $this->storekeeper();

        $this->arrive($headers, $warehouse, $item, 100, 1);
        $this->issue($headers, $warehouse, $item, 40);
        $this->arrive($headers, $warehouse, $item, 10, 1);

        // Act
        $rows = $this->ledger($headers, $warehouse, $item, ['movement_type' => 'purchase_arrival']);

        // Assert
        $this->assertCount(2, $rows);
        $this->assertSame(['70.000', '100.000'], array_column($rows, 'balance_after'));
    }

    public function test_a_balance_is_only_computed_for_one_shelf_in_one_warehouse(): void
    {
        // Arrange — two shelves in one warehouse: a running total across them is meaningless
        $warehouse = Warehouse::factory()->create();
        $a = StockItem::factory()->create();
        $b = StockItem::factory()->create();
        $headers = $this->storekeeper();

        $this->arrive($headers, $warehouse, $a, 10, 1);
        $this->arrive($headers, $warehouse, $b, 20, 1);

        // Act
        $rows = $this->withHeaders($headers)
            ->getJson('/api/v1/stock-movements?warehouse_id='.$warehouse->id)
            ->assertOk()
            ->json('data');

        // Assert — the sign still says which way each went; the balance does not pretend
        $this->assertSame(['20.000', '10.000'], array_column($rows, 'signed_quantity'));
        $this->assertSame([null, null], array_column($rows, 'balance_after'));
    }

    public function test_an_arrival_shows_what_it_cost_and_an_issue_what_fifo_charged_it(): void
    {
        // Arrange — 100 @ 10 then 100 @ 20; an issue of 150 draws all of the first and half of
        // the second, so it cost 1000 + 1000 = 2000, or 13.333 a unit.
        $warehouse = Warehouse::factory()->create();
        $item = StockItem::factory()->create();
        $headers = $this->accountant();

        $this->arrive($headers, $warehouse, $item, 100, 10);
        $this->arrive($headers, $warehouse, $item, 100, 20);
        $this->issue($headers, $warehouse, $item, 150);

        // Act
        $rows = $this->ledger($headers, $warehouse, $item);

        // Assert
        [$issue, $second, $first] = $rows;

        $this->assertSame('10.000', $first['unit_cost']);
        $this->assertSame('1000.00', $first['total_cost']);
        $this->assertSame('0.000', $first['uncosted_quantity']);

        $this->assertSame('20.000', $second['unit_cost']);
        $this->assertSame('2000.00', $second['total_cost']);

        $this->assertSame('2000.00', $issue['total_cost']);
        $this->assertSame('13.333', $issue['unit_cost']);
        $this->assertSame('0.000', $issue['uncosted_quantity']);
    }

    public function test_stock_nobody_priced_is_named_and_never_averaged(): void
    {
        // Arrange — an unpriced arrival, then a priced one, then an issue that spans both
        $warehouse = Warehouse::factory()->create();
        $item = StockItem::factory()->create();
        $headers = $this->accountant();

        $this->arrive($headers, $warehouse, $item, 100);
        $this->arrive($headers, $warehouse, $item, 100, 5);
        $this->issue($headers, $warehouse, $item, 150);

        // Act
        $rows = $this->ledger($headers, $warehouse, $item);

        // Assert — the unpriced arrival says so and shows no unit cost; the issue that drew on
        // it carries the part it could price and names the part it could not, and the average
        // is dropped rather than dragged down by zeros.
        [$issue, , $unpriced] = $rows;

        $this->assertNull($unpriced['unit_cost']);
        $this->assertSame('0.00', $unpriced['total_cost']);
        $this->assertSame('100.000', $unpriced['uncosted_quantity']);

        $this->assertNull($issue['unit_cost']);
        $this->assertSame('250.00', $issue['total_cost']);
        $this->assertSame('100.000', $issue['uncosted_quantity']);
    }

    public function test_a_reader_without_the_cost_permission_is_not_told(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $item = StockItem::factory()->create();
        $headers = $this->storekeeper();

        $this->arrive($headers, $warehouse, $item, 100, 10);

        // Act
        $rows = $this->ledger($headers, $warehouse, $item);

        // Assert — absent, not null: «you may not be told» is a different fact from «unknown».
        $this->assertArrayNotHasKey('unit_cost', $rows[0]);
        $this->assertArrayNotHasKey('total_cost', $rows[0]);
        $this->assertArrayNotHasKey('uncosted_quantity', $rows[0]);
        // The balance is not money; the storekeeper still gets it.
        $this->assertSame('100.000', $rows[0]['balance_after']);
    }

    public function test_a_page_of_the_ledger_costs_a_fixed_number_of_queries(): void
    {
        // Arrange — twenty rows on one shelf
        $warehouse = Warehouse::factory()->create();
        $item = StockItem::factory()->create();
        $headers = $this->accountant();

        foreach (range(1, 20) as $i) {
            $this->arrive($headers, $warehouse, $item, 10, 1);
        }

        // Act
        DB::enableQueryLog();
        $this->ledger($headers, $warehouse, $item, ['per_page' => 20]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Assert — auth, the count, the page, and one per eager-loaded relation; nothing per row.
        $this->assertLessThanOrEqual(10, $queries, "A page of twenty rows ran {$queries} queries.");
    }
}
