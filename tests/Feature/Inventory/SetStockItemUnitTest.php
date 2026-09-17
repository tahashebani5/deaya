<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Declaring what a shelf is counted in, once there is stock on it.
 *
 * **200 pieces are not 200 kilograms, and the old behaviour said they were.** This used to
 * relabel the balance and leave the figure alone, so a shelf holding 200 bags became a shelf
 * holding "200 kg" — a number nobody measured, in a unit nobody weighed it in, feeding every
 * costing and shortage answer from that moment on. The quantity is discarded now: what was
 * counted in the old unit cannot be carried into the new one.
 *
 * **And it is discarded through the ledger, not behind it.** {@see StockLedgerTest} states the
 * invariant this whole context exists to hold — for every (warehouse, stock item) the balance
 * equals the signed sum of its movements — so a balance that simply became zero would break the
 * one rule everything else serves. It leaves as an `Adjustment`, recorded in the unit it was
 * actually counted in, which is also what keeps «لماذا اختفى الرصيد؟» answerable a year later.
 *
 * **The question moved from the product to the pile, and this file moved with it.** It used to
 * hang off `PATCH /products/{id}/stock-unit` and `products.stock_unit`; a unit is a fact about
 * the heap, and while each product answered separately «كيس شحن سادة» and «كيس شحن مطبوع» could
 * insist that one pile was counted two different ways. The behaviour under test is unchanged —
 * which is the point of keeping this file rather than writing a new one.
 *
 * Arrange - Act - Assert throughout.
 */
class SetStockItemUnitTest extends TestCase
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

    /** A shelf counted by the piece — the side of the pair this endpoint changes. */
    private function pieceShelf(): StockItem
    {
        return StockItem::factory()->unit(PricingUnit::Piece)->create();
    }

    public function test_a_shelf_counted_in_the_old_unit_is_emptied_rather_than_relabelled(): void
    {
        // Arrange — 200 bags on the shelf, counted by the piece.
        $item = $this->pieceShelf();
        $warehouse = Warehouse::factory()->create();
        WarehouseStock::factory()->quantity('200')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $item->id,
            'unit' => PricingUnit::Piece,
        ]);

        // Act — the shop decides this product is stocked by weight from now on.
        $response = $this->withHeaders($this->manager())
            ->patchJson("/api/v1/stock-items/{$item->id}/unit", ['unit' => 'kilogram']);

        // Assert — the unit changed and the figure did NOT come with it. 200 was a count of bags;
        // carrying it over would assert 200 kg nobody ever put on a scale.
        $response->assertOk();
        $this->assertSame('kilogram', $response->json('data.unit'));

        $stock = WarehouseStock::query()
            ->where('stock_item_id', $item->id)
            ->firstOrFail();

        $this->assertSame(PricingUnit::Kilogram, $stock->unit);
        $this->assertSame('0.000', (string) $stock->quantity);
    }

    public function test_the_discarded_quantity_leaves_through_the_ledger(): void
    {
        // Arrange — the invariant StockLedgerTest states: the balance is the signed sum of the
        // movements. A balance that dropped to zero on its own would break it.
        $item = $this->pieceShelf();
        $warehouse = Warehouse::factory()->create();
        WarehouseStock::factory()->quantity('200')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $item->id,
            'unit' => PricingUnit::Piece,
        ]);

        // Act
        $this->withHeaders($this->manager())
            ->patchJson("/api/v1/stock-items/{$item->id}/unit", ['unit' => 'kilogram'])
            ->assertOk();

        // Assert — one adjustment out of that warehouse, for exactly what was there.
        //
        // **The unit is in the note, and it has to be.** `stock_movements` has no unit column —
        // a movement's unit is implicit in the stock item's `unit`, which this very operation
        // is about to change. So a reader looking back at "200" would infer kilograms unless the
        // row says otherwise in words. That is what makes the note load-bearing rather than
        // decoration, and why it is asserted.
        $movement = StockMovement::query()
            ->where('stock_item_id', $item->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(MovementType::Adjustment, $movement->movement_type);
        $this->assertSame($warehouse->id, $movement->from_warehouse_id);
        $this->assertNull($movement->to_warehouse_id);
        $this->assertSame('200.000', (string) $movement->quantity);
        $this->assertStringContainsString('تغيير وحدة المخزون', (string) $movement->notes);
        $this->assertStringContainsString(PricingUnit::Piece->label(), (string) $movement->notes);
    }

    public function test_every_warehouse_holding_the_item_is_emptied(): void
    {
        // Arrange — one item on two shelves. Emptying one and relabelling the other would
        // leave a balance asserting kilograms it was never weighed in.
        $item = $this->pieceShelf();
        $first = Warehouse::factory()->create();
        $second = Warehouse::factory()->create();

        foreach ([$first, $second] as $warehouse) {
            WarehouseStock::factory()->quantity('50')->create([
                'warehouse_id' => $warehouse->id,
                'stock_item_id' => $item->id,
                'unit' => PricingUnit::Piece,
            ]);
        }

        // Act
        $this->withHeaders($this->manager())
            ->patchJson("/api/v1/stock-items/{$item->id}/unit", ['unit' => 'kilogram'])
            ->assertOk();

        // Assert
        $balances = WarehouseStock::query()->where('stock_item_id', $item->id)->get();

        $this->assertCount(2, $balances);
        $balances->each(function (WarehouseStock $stock): void {
            $this->assertSame('0.000', (string) $stock->quantity);
            $this->assertSame(PricingUnit::Kilogram, $stock->unit);
        });
        $this->assertSame(2, StockMovement::query()
            ->where('stock_item_id', $item->id)
            ->where('movement_type', MovementType::Adjustment)
            ->count());
    }

    public function test_an_empty_shelf_is_relabelled_without_inventing_a_movement(): void
    {
        // Arrange — nothing to discard. A zero-quantity adjustment would be a line in the ledger
        // that records no event, and the ledger is read by people.
        $item = $this->pieceShelf();
        WarehouseStock::factory()->quantity('0')->create([
            'warehouse_id' => Warehouse::factory()->create()->id,
            'stock_item_id' => $item->id,
            'unit' => PricingUnit::Piece,
        ]);

        // Act
        $this->withHeaders($this->manager())
            ->patchJson("/api/v1/stock-items/{$item->id}/unit", ['unit' => 'kilogram'])
            ->assertOk();

        // Assert
        $this->assertSame(PricingUnit::Kilogram, WarehouseStock::query()
            ->where('stock_item_id', $item->id)->firstOrFail()->unit);
        $this->assertSame(0, StockMovement::query()
            ->where('stock_item_id', $item->id)->count());
    }

    public function test_setting_the_unit_it_already_has_touches_nothing(): void
    {
        // Arrange — a no-op must stay a no-op. Discarding a shelf because somebody re-picked the
        // unit already showing would be the worst possible reading of this endpoint.
        $item = $this->pieceShelf();
        WarehouseStock::factory()->quantity('200')->create([
            'warehouse_id' => Warehouse::factory()->create()->id,
            'stock_item_id' => $item->id,
            'unit' => PricingUnit::Piece,
        ]);

        // Act
        $this->withHeaders($this->manager())
            ->patchJson("/api/v1/stock-items/{$item->id}/unit", ['unit' => 'piece'])
            ->assertOk();

        // Assert — the 200 bags are still there.
        $this->assertSame('200.000', (string) WarehouseStock::query()
            ->where('stock_item_id', $item->id)->firstOrFail()->quantity);
        $this->assertSame(0, StockMovement::query()
            ->where('stock_item_id', $item->id)->count());
    }
}
