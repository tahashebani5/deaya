<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Enums\ManufacturingCostType;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\ManufacturingCostRate;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\ProductionCostEntry;
use App\Domain\Order\Support\TransitionFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * What entering `ready` costs an order's lines: the FIFO material draw, plus whatever
 * manufacturing rates apply — and what happens when one of those inputs is missing.
 *
 * Arrange - Act - Assert throughout.
 */
class ProductionCostTest extends TestCase
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
            // The way back into printing: a correction goes to the designer and forward again.
            PermissionName::MoveOrderToDesigning->value,
            PermissionName::ViewInventory->value,
            PermissionName::ManageInventory->value,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * Walks a new order all the way to «جاهزة», through the two things that now happen on the
     * way: the warehouse hands it to the press at «جاهزة للطباعة», **naming the shelf the stock
     * leaves from**, and «جاهزة» is where the press finishes and production cost is applied.
     *
     * The two used to be one step. Material cost still lands with the deduction — so at the
     * handover — while labour, machine and overhead land here, because those are the press
     * running and it has not run when the warehouse lets the goods go.
     */
    private function enterReady(array $headers, Order $order, Warehouse $warehouse): void
    {
        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::ReadyToPrint->value,
            'fields' => ['warehouse_id' => $warehouse->id],
        ])->assertOk();

        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Printing->value,
        ])->assertOk();

        // No warehouse this time: the stock has already gone, so the move does not offer one —
        // and the request refuses a key its transition never described.
        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Ready->value,
        ])->assertOk();
    }

    public function test_entering_printing_costs_material_labor_and_overhead_onto_the_line_and_the_order(): void
    {
        // Arrange — a real costed batch, and rates for both cost types this product carries
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $warehouse = Warehouse::factory()->create();
        $headers = $this->foreman();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->stock_item_id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 100,
            'unit_cost' => 4,
        ])->assertCreated();

        ManufacturingCostRate::factory()->forProduct($product)->type(ManufacturingCostType::Labor)->rate('2.000')->create();
        ManufacturingCostRate::factory()->forProduct($product)->type(ManufacturingCostType::Overhead)->rate('0.500')->create();

        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => '100',
        ]);

        // Act
        $this->enterReady($headers, $order, $warehouse);

        // Assert — material: 100 @ 4 = 400.00; labor: 100 @ 2 = 200.00; overhead: 100 @ 0.5 = 50.00
        $item->refresh();
        $this->assertSame('400.00', (string) $item->material_cost);
        $this->assertSame('200.00', (string) $item->labor_cost);
        $this->assertSame('50.00', (string) $item->overhead_cost);
        $this->assertSame('650.00', (string) $item->cogs);

        $order->refresh();
        $this->assertSame('650.00', (string) $order->total_cogs);
        $this->assertSame(
            bcsub((string) $order->grand_total, '650.00', 2),
            $order->grossProfit(),
        );

        // And the entries are individually recorded, not just the cached sum
        $this->assertDatabaseHas('production_cost_entries', [
            'order_item_id' => $item->id, 'cost_type' => 'labor', 'quantity' => '100.000',
            'rate' => '2.000', 'amount' => '200.00',
        ]);
        $this->assertDatabaseHas('production_cost_entries', [
            'order_item_id' => $item->id, 'cost_type' => 'overhead', 'amount' => '50.00',
        ]);
    }

    public function test_a_cost_type_with_no_applicable_rate_is_skipped_not_zeroed(): void
    {
        // Arrange — a product-specific labor rate, but nothing for overhead at all
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $warehouse = Warehouse::factory()->create();
        $headers = $this->foreman();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->stock_item_id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 50,
            'unit_cost' => 2,
        ])->assertCreated();

        ManufacturingCostRate::factory()->forProduct($product)->type(ManufacturingCostType::Labor)->rate('1.000')->create();

        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => '50',
        ]);

        // Act
        $this->enterReady($headers, $order, $warehouse);

        // Assert — overhead was never computed, not computed as 0
        $item->refresh();
        $this->assertSame('100.00', (string) $item->material_cost); // 50 @ 2
        $this->assertSame('50.00', (string) $item->labor_cost);     // 50 @ 1
        $this->assertNull($item->overhead_cost);
        $this->assertSame('150.00', (string) $item->cogs);          // material + labor only
    }

    public function test_the_default_rate_applies_when_the_product_has_none_of_its_own(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $warehouse = Warehouse::factory()->create();
        $headers = $this->foreman();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->stock_item_id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 20,
            'unit_cost' => 1,
        ])->assertCreated();

        ManufacturingCostRate::factory()->default()->type(ManufacturingCostType::Labor)->rate('0.750')->create();

        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => '20',
        ]);

        // Act
        $this->enterReady($headers, $order, $warehouse);

        // Assert — 20 @ 0.75 = 15.00
        $this->assertSame('15.00', (string) $item->refresh()->labor_cost);
    }

    public function test_a_product_specific_rate_wins_over_the_default(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $warehouse = Warehouse::factory()->create();
        $headers = $this->foreman();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->stock_item_id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 10,
            'unit_cost' => 1,
        ])->assertCreated();

        ManufacturingCostRate::factory()->default()->type(ManufacturingCostType::Labor)->rate('9.000')->create();
        ManufacturingCostRate::factory()->forProduct($product)->type(ManufacturingCostType::Labor)->rate('2.000')->create();

        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => '10',
        ]);

        // Act
        $this->enterReady($headers, $order, $warehouse);

        // Assert — 10 @ 2, not 10 @ 9
        $this->assertSame('20.00', (string) $item->refresh()->labor_cost);
    }

    public function test_a_variant_specific_rate_wins_over_both_its_product_and_the_default(): void
    {
        // Arrange — all three tiers configured for the same cost type; the size's own rate
        // must win over both.
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $warehouse = Warehouse::factory()->create();
        $headers = $this->foreman();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->stock_item_id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 10,
            'unit_cost' => 1,
        ])->assertCreated();

        ManufacturingCostRate::factory()->default()->type(ManufacturingCostType::Labor)->rate('9.000')->create();
        ManufacturingCostRate::factory()->forProduct($product)->type(ManufacturingCostType::Labor)->rate('5.000')->create();
        ManufacturingCostRate::factory()->forVariant($variant)->type(ManufacturingCostType::Labor)->rate('3.000')->create();

        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => '10',
        ]);

        // Act
        $this->enterReady($headers, $order, $warehouse);

        // Assert — 10 @ 3, not 10 @ 5 and not 10 @ 9
        $this->assertSame('30.00', (string) $item->refresh()->labor_cost);
    }

    public function test_an_order_already_called_ready_does_not_reapply_manufacturing_rates(): void
    {
        // Arrange — `ready` has no way back to `printing`/`designing` (see
        // `OrderStatus::allowedNext()`), so this cannot be exercised by a real reprint: an order
        // that has reached `ready` never sees the transition fire again through legitimate use.
        // What is left to test is the guard itself, by putting the order in the state a second
        // `ready` entry would find it in.
        //
        // **The guard is `ready_at`, and it used to be `stock_deducted_at`.** The two once
        // happened in one breath; now the stock leaves at «جاهزة للطباعة», so every printed order
        // reaches here already deducted and the old fact would have stopped costing anything at
        // all. What has-this-been-costed actually depends on is whether the order has been called
        // ready before.
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $warehouse = Warehouse::factory()->create();
        $headers = $this->foreman();

        ManufacturingCostRate::factory()->forProduct($product)->type(ManufacturingCostType::Labor)->rate('1.000')->create();

        $order = Order::factory()->status(OrderStatus::Printing)->create([
            'stock_deducted_at' => now(),
            'ready_at' => now(),
            'fulfillment_warehouse_id' => $warehouse->id,
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => '40',
        ]);

        // Act
        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Ready->value,
        ])->assertOk();

        // Assert — the guard refuses to cost it, since `stock_deducted_at` already said stock
        // had already left the warehouse for this order.
        $this->assertSame(0, ProductionCostEntry::query()->where('order_item_id', $item->id)->count());
    }

    /**
     * **«كم تكلفتنا القطعة؟» — the question a total of 400.00 does not answer.**
     *
     * The line already carries what it cost in full; what nobody could read off the screen was
     * the rate behind it. It is not stored, and it is not going to be: `material_cost` is a FIFO
     * draw of the batches the line actually consumed, so the per-unit figure is that draw
     * divided by what left the shelf — and on a line that ate two layers at different prices, it
     * is their weighted average, which no single `stock_batches.unit_cost` row states.
     *
     * Three decimals, like `unit_price` beside it, because the two are read in one glance.
     */
    public function test_the_line_says_what_one_unit_of_material_cost_it(): void
    {
        // Arrange — one shelf, one batch at 4.000, a hundred of it sold and printed.
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $warehouse = Warehouse::factory()->create();
        $headers = $this->foreman();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->stock_item_id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 100,
            'unit_cost' => 4,
        ])->assertCreated();

        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => '100',
        ]);

        // Act
        $this->enterReady($headers, $order, $warehouse);

        // Assert — 400.00 over 100 is the 4.000 the batch was bought at, said per unit and in
        // the unit the shelf counts in.
        $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.material_cost', '400.00')
            ->assertJsonPath('data.items.0.unit_material_cost', '4.000')
            ->assertJsonPath('data.items.0.stock_unit_label', $product->pricing_unit->label());
    }

    /**
     * **The figure is per shelf unit, never per sold unit.**
     *
     * 300 bags weighing 12.5 kilograms together cost what those kilograms cost; dividing that by
     * 300 would invent a per-bag material cost out of a scale reading, and the whole reason
     * `warehouse_quantity` is typed by hand is that no factor converts the one into the other —
     * see COST-TRACKING-UNIT-CONVERSION.md §4. So the divisor is what left the warehouse, and
     * the label sent beside it is the shelf's own unit.
     */
    public function test_a_line_weighed_onto_the_scale_is_costed_per_kilogram_not_per_bag(): void
    {
        // Arrange — sold by the piece, stocked by the kilo, 12.5 kg off a shelf bought at 8.000.
        $product = Product::factory()->create(['pricing_unit' => PricingUnit::Piece]);
        $shelf = StockItem::factory()->unit(PricingUnit::Kilogram)->create();
        $variant = ProductVariant::factory()->for($product)->create(['stock_item_id' => $shelf->id]);
        $warehouse = Warehouse::factory()->create();
        $headers = $this->foreman();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $shelf->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 100,
            'unit_cost' => 8,
        ])->assertCreated();

        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'pricing_unit' => PricingUnit::Piece,
            'quantity' => '300',
        ]);

        // Act — the weight is read off the scale at the handover, which is the only place a line
        // whose units differ can get one.
        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::ReadyToPrint->value,
            'fields' => [
                'warehouse_id' => $warehouse->id,
                TransitionFields::stockQuantityKey($item) => '12.5',
            ],
        ])->assertOk();

        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Printing->value,
        ])->assertOk();

        // Asked once more at «جاهزة» — the press confirms what it actually used, unchanged here.
        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Ready->value,
            'fields' => [TransitionFields::stockQuantityKey($item) => '12.5'],
        ])->assertOk();

        // Assert — 100.00 over 12.5 kilograms, and «كجم» said out loud so nobody reads it
        // as the cost of a bag.
        $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.material_cost', '100.00')
            ->assertJsonPath('data.items.0.unit_material_cost', '8.000')
            ->assertJsonPath('data.items.0.stock_unit_label', PricingUnit::Kilogram->label());
    }

    /**
     * Null, not zero, for the same reason every other cost on the line is — and for one more:
     * there is nothing to divide by either, on a line whose quantity is entirely shortage.
     */
    public function test_a_line_that_has_not_been_printed_has_no_unit_cost_either(): void
    {
        // Arrange — an order still sitting at «جديدة», nothing deducted, nothing costed.
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $headers = $this->foreman();

        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => '100',
        ]);

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}")->assertOk();

        // Assert
        $response->assertJsonPath('data.items.0.material_cost', null)
            ->assertJsonPath('data.items.0.unit_material_cost', null);
    }
}
