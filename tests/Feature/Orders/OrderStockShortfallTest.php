<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Enums\PricingMode;
use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Telling a foreman what the shelves say, before and after a refusal.
 *
 * **Both halves of this are reads, and neither changes what the deduction does.**
 * `DeductOrderStock` refuses exactly what it always refused, with the same message; what is new
 * is that the «نواقص» form can arrive with its boxes filled in rather than leaving somebody to
 * work four numbers out of one sentence about a balance.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderStockShortfallTest extends TestCase
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
    private function storeman(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewOrders->value,
            PermissionName::MoveOrderToShortage->value,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** A size that is stocked, so it has a shelf to be weighed against. */
    private function stockedVariant(string $label): ProductVariant
    {
        $product = Product::factory()->create([
            'name' => 'كيس شحن',
            'pricing_mode' => PricingMode::Tiered,
        ]);

        return ProductVariant::factory()->create([
            'product_id' => $product->getKey(),
            'label' => $label,
            'stock_item_id' => StockItem::factory()->create(['unit' => PricingUnit::Piece])->getKey(),
        ]);
    }

    private function line(Order $order, ProductVariant $variant, string $quantity, int $sort = 0): OrderItem
    {
        return OrderItem::factory()->for($order)->create([
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->getKey(),
            'variant_label' => $variant->label,
            'pricing_unit' => PricingUnit::Piece,
            'quantity' => $quantity,
            'sort_order' => $sort,
        ]);
    }

    // ── the read ────────────────────────────────────────────────────────────────────────

    public function test_it_reports_what_each_line_is_short_of_in_one_warehouse(): void
    {
        // Arrange — 300 ordered, 270 on the shelf.
        $headers = $this->storeman();
        $order = Order::factory()->status(OrderStatus::New)->create();
        $variant = $this->stockedVariant('25*35');
        $item = $this->line($order, $variant, '300.000');

        $warehouse = Warehouse::factory()->create();
        WarehouseStock::factory()->quantity('270')->create([
            'warehouse_id' => $warehouse->getKey(),
            'stock_item_id' => $variant->stock_item_id,
        ]);

        // Act
        $response = $this->getJson(
            "/api/v1/orders/{$order->getKey()}/stock-shortfall?warehouse_id={$warehouse->getKey()}",
            $headers,
        );

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.is_short', true)
            ->assertJsonPath('data.available_scope', 'warehouse')
            ->assertJsonPath('data.warehouse_id', $warehouse->getKey())
            ->assertJsonPath('data.lines.0.line_id', $item->getKey())
            ->assertJsonPath('data.lines.0.required', '300.000')
            ->assertJsonPath('data.lines.0.available', '270.000')
            // The number to type into this line's «الناقص من …» box.
            ->assertJsonPath('data.lines.0.suggested_shortage', '30.000');
    }

    public function test_a_covered_order_is_short_of_nothing(): void
    {
        // Arrange
        $headers = $this->storeman();
        $order = Order::factory()->status(OrderStatus::New)->create();
        $variant = $this->stockedVariant('25*35');
        $this->line($order, $variant, '300.000');

        $warehouse = Warehouse::factory()->create();
        WarehouseStock::factory()->quantity('500')->create([
            'warehouse_id' => $warehouse->getKey(),
            'stock_item_id' => $variant->stock_item_id,
        ]);

        // Act
        $response = $this->getJson(
            "/api/v1/orders/{$order->getKey()}/stock-shortfall?warehouse_id={$warehouse->getKey()}",
            $headers,
        );

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.is_short', false)
            ->assertJsonPath('data.lines.0.suggested_shortage', '0.000');
    }

    /**
     * Without a warehouse it answers from every shelf at once — the only figure available before
     * an order has chosen one, and labelled as the weaker fact it is.
     */
    public function test_without_a_warehouse_it_sums_every_shelf(): void
    {
        // Arrange — 150 in one site and 120 in another: 270 in total, still 30 short of 300.
        $headers = $this->storeman();
        $order = Order::factory()->status(OrderStatus::New)->create();
        $variant = $this->stockedVariant('25*35');
        $this->line($order, $variant, '300.000');

        foreach (['150', '120'] as $quantity) {
            WarehouseStock::factory()->quantity($quantity)->create([
                'warehouse_id' => Warehouse::factory()->create()->getKey(),
                'stock_item_id' => $variant->stock_item_id,
            ]);
        }

        // Act
        $response = $this->getJson("/api/v1/orders/{$order->getKey()}/stock-shortfall", $headers);

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.available_scope', 'all_warehouses')
            ->assertJsonPath('data.warehouse_id', null)
            ->assertJsonPath('data.lines.0.available', '270.000')
            ->assertJsonPath('data.lines.0.suggested_shortage', '30.000');
    }

    /**
     * The apportionment: a shortfall is a fact about a pile, a shortage is recorded against a
     * line, and two lines can draw on one pile. The earlier line is filled first and the later
     * one goes short — arbitrary, predictable, and correct in the only way that matters: the
     * total.
     */
    public function test_two_lines_on_one_shelf_are_apportioned_in_order(): void
    {
        // Arrange — one shelf of 250; two lines wanting 200 and 100.
        $headers = $this->storeman();
        $order = Order::factory()->status(OrderStatus::New)->create();
        $variant = $this->stockedVariant('25*35');

        $first = $this->line($order, $variant, '200.000', sort: 0);
        $second = $this->line($order, $variant, '100.000', sort: 1);

        $warehouse = Warehouse::factory()->create();
        WarehouseStock::factory()->quantity('250')->create([
            'warehouse_id' => $warehouse->getKey(),
            'stock_item_id' => $variant->stock_item_id,
        ]);

        // Act
        $response = $this->getJson(
            "/api/v1/orders/{$order->getKey()}/stock-shortfall?warehouse_id={$warehouse->getKey()}",
            $headers,
        );

        // Assert — the first line is covered, the second absorbs the whole 50.
        $response->assertOk()
            ->assertJsonPath('data.is_short', true)
            ->assertJsonPath('data.lines.0.line_id', $first->getKey())
            ->assertJsonPath('data.lines.0.suggested_shortage', '0.000')
            ->assertJsonPath('data.lines.1.line_id', $second->getKey())
            ->assertJsonPath('data.lines.1.suggested_shortage', '50.000');
    }

    public function test_reading_it_needs_the_shortage_grant(): void
    {
        // Arrange — may read orders, may not declare a shortage.
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::ViewOrders->value);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
        $order = Order::factory()->create();

        // Act
        $response = $this->getJson("/api/v1/orders/{$order->getKey()}/stock-shortfall", $headers);

        // Assert
        $response->assertForbidden();
    }

    // ── the hint ────────────────────────────────────────────────────────────────────────

    /**
     * The balance is printed beside the box that asks for the shortage — and **not** written
     * into it. The balance is a record; the shortage is an observation, and the two disagree
     * exactly when this screen matters.
     */
    public function test_the_shortage_form_prints_what_the_shelves_hold(): void
    {
        // Arrange
        $headers = $this->storeman();
        $order = Order::factory()->status(OrderStatus::New)->create();
        $variant = $this->stockedVariant('25*35');
        $item = $this->line($order, $variant, '300.000');

        WarehouseStock::factory()->quantity('270')->create([
            'warehouse_id' => Warehouse::factory()->create()->getKey(),
            'stock_item_id' => $variant->stock_item_id,
        ]);

        // Act
        $response = $this->getJson("/api/v1/orders/{$order->getKey()}", $headers);

        // Assert
        $response->assertOk();

        $field = collect($response->json('data.available_transitions'))
            ->firstWhere('status', OrderStatus::Shortage->value);

        $hint = collect($field['fields'] ?? [])
            ->firstWhere('key', "shortage_{$item->getKey()}")['hint'] ?? '';

        // «270» و«300», not «270.000» و«300.000»: the hint says the figure, and the scale
        // belongs to the column it was read out of. That is DecimalText's rule, which arrived
        // with the partial-delivery work and which this hint now follows like every other.
        $this->assertStringContainsString('270', $hint, 'the balance is printed');
        $this->assertStringNotContainsString('270.000', $hint, 'without the column padding');
        $this->assertStringContainsString('في كل المخازن', $hint, 'and labelled as a sum across sites');
        $this->assertStringContainsString('من أصل 300', $hint, 'beside what was ordered');
    }

    public function test_a_size_with_no_shelf_says_nothing_about_stock(): void
    {
        // Arrange — a variant the warehouse does not carry.
        $headers = $this->storeman();
        $order = Order::factory()->status(OrderStatus::New)->create();
        $item = OrderItem::factory()->for($order)->create(['quantity' => '300.000']);

        ProductVariant::query()->whereKey($item->product_variant_id)->update(['stock_item_id' => null]);

        // Act
        $response = $this->getJson("/api/v1/orders/{$order->getKey()}", $headers);

        // Assert
        $response->assertOk();

        $field = collect($response->json('data.available_transitions'))
            ->firstWhere('status', OrderStatus::Shortage->value);

        $hint = collect($field['fields'] ?? [])
            ->firstWhere('key', "shortage_{$item->getKey()}")['hint'] ?? '';

        // «المتوفر ٠» about something never stocked is a fact about the catalogue, not about today.
        $this->assertStringNotContainsString('المتوفر', $hint);
        $this->assertStringContainsString('من أصل', $hint);
    }
}
