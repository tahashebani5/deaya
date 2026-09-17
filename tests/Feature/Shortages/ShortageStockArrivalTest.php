<?php

declare(strict_types=1);

namespace Tests\Feature\Shortages;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Enums\ShortageRevision;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\OrderService;
use App\Domain\Shortage\Actions\SyncShortagesFromOrder;
use App\Domain\Shortage\Models\Shortage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Recording a supply is the goods arriving.
 *
 * **The test that justifies the whole change is {@see test_an_order_can_reach_ready_once_its_shortage_was_bought()}.**
 * Before it, a supply was a money note: the shortage closed, the order's lines were cleared, and
 * the order was then refused at «جاهزة» because nothing had ever reached the shelf it draws from.
 * The rest of this file pins the mechanics that make that one pass.
 *
 * Arrange - Act - Assert throughout.
 */
class ShortageStockArrivalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    /**
     * @return array<string, string>
     */
    private function clerk(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewShortages->value,
            PermissionName::ManageShortages->value,
            PermissionName::RecordShortageSupplies->value,
            PermissionName::ReverseShortageSupplies->value,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * An order short of 30 of the 300 it ordered, with its shortage already mirrored.
     *
     * @return array{0: Order, 1: OrderItem, 2: Shortage}
     */
    private function shortOrder(): array
    {
        $order = Order::factory()->status(OrderStatus::Shortage)->create();

        $item = OrderItem::factory()->for($order)->create([
            'quantity' => '300.000',
            'unit_price' => '1.550',
            'line_total' => '465.00',
        ]);

        app(OrderService::class)->setShortages(
            $order->refresh(),
            [$item->getKey() => '30'],
            null,
            ShortageRevision::Declared,
        );

        app(SyncShortagesFromOrder::class)((int) $order->getKey(), ShortageRevision::Declared);

        $shortage = Shortage::query()->where('order_item_id', $item->getKey())->firstOrFail();

        return [$order->refresh(), $item, $shortage];
    }

    private function shelfFor(OrderItem $item): int
    {
        return (int) ProductVariant::query()
            ->whereKey($item->product_variant_id)
            ->value('stock_item_id');
    }

    // ── the arrival ─────────────────────────────────────────────────────────────────────

    public function test_a_supply_posts_an_arrival_at_what_was_paid(): void
    {
        // Arrange
        $headers = $this->clerk();
        [, $item, $shortage] = $this->shortOrder();
        $warehouse = Warehouse::factory()->create();

        // Act
        $response = $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '30',
            'amount' => '760',
            'method' => PaymentMethod::Cash->value,
            'warehouse_id' => $warehouse->getKey(),
        ], $headers);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.warehouse_id', $warehouse->getKey())
            ->assertJsonPath('data.moved_stock', true);

        $movement = StockMovement::query()
            ->whereKey($response->json('data.stock_movement_id'))
            ->firstOrFail();

        $this->assertSame(MovementType::PurchaseArrival, $movement->movement_type);
        $this->assertSame($this->shelfFor($item), (int) $movement->stock_item_id);
        $this->assertSame((int) $warehouse->getKey(), (int) $movement->to_warehouse_id);
        $this->assertSame('30.000', (string) $movement->quantity);

        // **The cost is asserted on the layer, not on the movement**, because that is where it
        // lives and what the order will actually draw: `stock_movements` carries the quantity and
        // `stock_batches` carries what each unit of it cost. 760 ÷ 30 = 25.333, derived by the
        // action and never accepted from the payload.
        $batch = StockBatch::query()
            ->where('stock_item_id', $this->shelfFor($item))
            ->where('warehouse_id', $warehouse->getKey())
            ->firstOrFail();

        $this->assertSame('25.333', (string) $batch->unit_cost);
        $this->assertSame('30.000', (string) $batch->quantity_received);
        $this->assertSame('30.000', (string) $batch->quantity_remaining);
    }

    public function test_the_goods_land_on_the_shelf(): void
    {
        // Arrange
        $headers = $this->clerk();
        [, $item, $shortage] = $this->shortOrder();
        $warehouse = Warehouse::factory()->create();

        // Act
        $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '30',
            'amount' => '760',
            'method' => PaymentMethod::Cash->value,
            'warehouse_id' => $warehouse->getKey(),
        ], $headers)->assertCreated();

        // Assert
        $balance = WarehouseStock::query()
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_item_id', $this->shelfFor($item))
            ->value('quantity');

        $this->assertSame('30.000', (string) $balance);
    }

    /**
     * The reason the whole change exists.
     *
     * The order is short, the sacks are bought, and the order then walks to «جاهزة» — which draws
     * the **full** 300 off the shelf, shortage or no shortage. Without the arrival this refuses
     * with `OrderStockShortfall`, talking about a balance rather than about the purchase.
     */
    public function test_an_order_can_reach_ready_once_its_shortage_was_bought(): void
    {
        // Arrange — the shelf holds the 270 that were never missing.
        $headers = $this->clerk();
        [$order, $item, $shortage] = $this->shortOrder();
        $warehouse = Warehouse::factory()->create();

        WarehouseStock::factory()->quantity('270')->create([
            'warehouse_id' => $warehouse->getKey(),
            'stock_item_id' => $this->shelfFor($item),
        ]);

        // Act — the missing 30 are bought in.
        $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '30',
            'amount' => '760',
            'method' => PaymentMethod::Cash->value,
            'warehouse_id' => $warehouse->getKey(),
        ], $headers)->assertCreated();

        // Assert — the shelf now covers the whole order, which is what «جاهزة» will ask of it.
        $onShelf = WarehouseStock::query()
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_item_id', $this->shelfFor($item))
            ->value('quantity');

        $this->assertSame('300.000', (string) $onShelf);
        $this->assertSame(
            '300.000',
            $item->refresh()->producedQuantity(),
            'the deduction asks for the full ordered quantity, not the billable one',
        );
        $this->assertNull($item->shortage_quantity, 'and the invoice is whole again');
    }

    // ── the reversal ────────────────────────────────────────────────────────────────────

    public function test_reversing_a_supply_takes_the_goods_back_off_the_shelf(): void
    {
        // Arrange
        $headers = $this->clerk();
        [, $item, $shortage] = $this->shortOrder();
        $warehouse = Warehouse::factory()->create();

        $supplyId = $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '30',
            'amount' => '760',
            'method' => PaymentMethod::Cash->value,
            'warehouse_id' => $warehouse->getKey(),
        ], $headers)->assertCreated()->json('data.id');

        // Act
        $this->postJson(
            "/api/v1/shortages/{$shortage->getKey()}/supplies/{$supplyId}/reversal",
            ['reason' => 'أُدخلت مرتين'],
            $headers,
        )->assertCreated();

        // Assert
        $balance = WarehouseStock::query()
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_item_id', $this->shelfFor($item))
            ->value('quantity');

        $this->assertSame('0.000', (string) $balance);

        $this->assertSame(
            1,
            StockMovement::query()->where('movement_type', MovementType::ArrivalReversal->value)->count(),
        );
    }

    // ── the two refusals ────────────────────────────────────────────────────────────────

    public function test_a_stockable_shortage_demands_a_warehouse(): void
    {
        // Arrange
        $headers = $this->clerk();
        [, , $shortage] = $this->shortOrder();

        // Act
        $response = $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '30',
            'amount' => '760',
            'method' => PaymentMethod::Cash->value,
        ], $headers);

        // Assert — refused here rather than a week later at «جاهزة».
        $response->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
        $this->assertSame('0.000', (string) $shortage->refresh()->supplied_quantity);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_a_free_text_shortage_records_money_and_moves_nothing(): void
    {
        // Arrange — «شريط لاصق عريض»: no product, so no shelf.
        $headers = $this->clerk();
        $shortage = Shortage::factory()->create([
            'name' => 'شريط لاصق عريض',
            'required_quantity' => '12.000',
        ]);

        // Act
        $response = $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '12',
            'amount' => '45',
            'method' => PaymentMethod::Cash->value,
        ], $headers);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.moved_stock', false)
            ->assertJsonPath('data.warehouse_id', null)
            ->assertJsonPath('data.stock_movement_id', null);

        $this->assertSame('45.00', (string) $shortage->refresh()->total_paid);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_a_warehouse_on_a_free_text_shortage_is_refused(): void
    {
        // Arrange
        $headers = $this->clerk();
        $shortage = Shortage::factory()->create(['required_quantity' => '12.000']);
        $warehouse = Warehouse::factory()->create();

        // Act
        $response = $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '12',
            'amount' => '45',
            'method' => PaymentMethod::Cash->value,
            'warehouse_id' => $warehouse->getKey(),
        ], $headers);

        // Assert — a misunderstanding worth naming: the caller believes goods are about to move.
        $response->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_the_shortage_says_whether_a_warehouse_will_be_asked_for(): void
    {
        // Arrange
        $headers = $this->clerk();
        [, , $fromOrder] = $this->shortOrder();
        $manual = Shortage::factory()->create();

        // Act
        $stockable = $this->getJson("/api/v1/shortages/{$fromOrder->getKey()}", $headers);
        $free = $this->getJson("/api/v1/shortages/{$manual->getKey()}", $headers);

        // Assert — the app draws or omits the picker from this, never from product_variant_id.
        $stockable->assertOk()->assertJsonPath('data.is_stockable', true);
        $free->assertOk()->assertJsonPath('data.is_stockable', false);
    }
}
