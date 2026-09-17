<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A line whose bags have already left the shelf may not be swapped for a different line.
 *
 * **«جاهزة للطباعة» closes the lines and «قيد الطباعة» opened them again.** `itemsAreEditable()`
 * lists New, Designing, Shortage and Printing — and the stock leaves at ReadyToPrint, which sits
 * between the last two. So an order could be fulfilled, moved on to the press, and then have its
 * whole line set replaced.
 *
 * What that cost, before this guard: `SyncOrderItems` soft-deletes the old lines, and each one
 * carries the `fulfillment_stock_movement_id` of the movement that drew its bags.
 * `ReverseOrderStockDeduction` iterates `$order->items`, which excludes trashed rows — so
 * **the bags were never credited back to the shelf**, and `orders.total_cogs` went on summing
 * lines that no longer existed.
 *
 * The sanctioned correction is still there and untouched: reach «جاهزة» with a corrected
 * `warehouse_quantity` and `RestateOrderStockDeduction` reverses the draw in full and posts a
 * fresh one. This closes the door that loses stock, not the door that fixes a number.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderItemsLockAfterFulfilmentTest extends TestCase
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
    private function foreman(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewOrders->value,
            PermissionName::ManageOrders->value,
            PermissionName::MoveOrderToReadyToPrint->value,
            PermissionName::MoveOrderToPrinting->value,
            PermissionName::ViewInventory->value,
            PermissionName::ManageInventory->value,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** A size standing on a shelf of its own, priced from the catalogue like any real one. */
    private function sellableSize(): ProductVariant
    {
        $product = Product::factory()->create(['pricing_unit' => PricingUnit::Piece]);

        $variant = ProductVariant::factory()->for($product)->create([
            'label' => '25*35',
            'stock_item_id' => StockItem::factory()->unit(PricingUnit::Piece)->create()->getKey(),
        ]);

        // A listed price wins over anything a request sends (see AddOrderItem), so a size with
        // no tier makes an edit fail on pricing long before it reaches the guard under test.
        ProductPriceTier::factory()->create([
            'product_variant_id' => $variant->getKey(),
            'min_quantity' => '100',
            'unit_price' => '1.100',
        ]);

        return $variant;
    }

    /**
     * A new order of one line, and a warehouse holding a costed layer of what it will draw.
     *
     * The layer matters: without it the draw has nothing to consume and the reversal has nothing
     * to credit back, so the very thing this test measures would not exist either way.
     *
     * @return array{0: Order, 1: OrderItem, 2: Warehouse}
     */
    private function order(ProductVariant $size, string $quantity = '300', string $onShelf = '1000'): array
    {
        $order = Order::factory()->create();

        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $size->product_id,
            'product_variant_id' => $size->getKey(),
            'variant_label' => $size->label,
            'quantity' => $quantity,
            'pricing_unit' => PricingUnit::Piece,
        ]);

        $warehouse = Warehouse::factory()->create();
        $shelf = StockItem::query()->findOrFail($size->stock_item_id);

        WarehouseStock::factory()->quantity($onShelf)->unit($shelf->unit)->create([
            'warehouse_id' => $warehouse->getKey(),
            'stock_item_id' => $shelf->getKey(),
        ]);

        StockBatch::factory()->create([
            'warehouse_id' => $warehouse->getKey(),
            'stock_item_id' => $shelf->getKey(),
            'quantity_received' => $onShelf,
            'quantity_remaining' => $onShelf,
            'unit_cost' => '2.000',
            'unit' => $shelf->unit,
        ]);

        return [$order->refresh(), $item, $warehouse];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function move(array $headers, Order $order, OrderStatus $to, array $fields = []): TestResponse
    {
        return $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/status",
            array_filter(['status' => $to->value, 'fields' => $fields ?: null]),
        );
    }

    /** Walk an order to the press, drawing its bags on the way through. */
    private function onThePress(array $headers, Order $order, Warehouse $warehouse): Order
    {
        $this->move($headers, $order, OrderStatus::ReadyToPrint, ['warehouse_id' => $warehouse->getKey()])
            ->assertOk();
        $this->move($headers, $order->refresh(), OrderStatus::Printing)->assertOk();

        return $order->refresh();
    }

    private function balanceOf(Warehouse $warehouse, ProductVariant $size): string
    {
        return (string) (WarehouseStock::query()
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_item_id', $size->stock_item_id)
            ->first()?->quantity ?? '0.000');
    }

    // ─────────────────────────── the door that loses stock ───────────────────────────

    public function test_a_line_whose_bags_already_left_the_shelf_cannot_be_replaced(): void
    {
        // Arrange — 300 drawn out of 1,000, and the order sitting on the press.
        $size = $this->sellableSize();
        [$order, $item, $warehouse] = $this->order($size);
        $headers = $this->foreman();
        $order = $this->onThePress($headers, $order, $warehouse);

        $this->assertNotNull($item->refresh()->fulfillment_stock_movement_id);
        $this->assertSame('700.000', $this->balanceOf($warehouse, $size));

        // Act — the whole line set replaced, which is what the form sends when somebody edits an
        // order's items at all.
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->id}", [
            'city_id' => $order->city_id,
            'items' => [[
                'product_id' => $size->product_id,
                'product_variant_id' => $size->getKey(),
                'quantity' => '500',
            ]],
        ]);

        // Assert — refused, the fulfilled line still standing with its movement, and the shelf
        // exactly where the draw left it. Before the guard the line was trashed, the reversal
        // walked past it, and those 300 bags were never seen again.
        $response->assertStatus(422)->assertJsonValidationErrors('items');
        $this->assertNull($item->refresh()->deleted_at);
        $this->assertNotNull($item->fulfillment_stock_movement_id);
        $this->assertSame('700.000', $this->balanceOf($warehouse, $size));
        $this->assertSame(1, $order->items()->count());
    }

    public function test_an_edit_that_leaves_the_lines_alone_is_still_allowed_on_the_press(): void
    {
        // Arrange
        $size = $this->sellableSize();
        [$order, , $warehouse] = $this->order($size);
        $headers = $this->foreman();
        $order = $this->onThePress($headers, $order, $warehouse);

        // Act — `items` omitted entirely, which is what an edit to anything else sends.
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->id}", [
            'city_id' => $order->city_id,
            'notes' => 'العميل يريدها قبل الخميس',
        ]);

        // Assert — a guard that closed this would make an order on the press uneditable over a
        // note nobody objected to.
        $response->assertOk()->assertJsonPath('data.notes', 'العميل يريدها قبل الخميس');
    }

    public function test_lines_are_still_replaceable_while_the_bags_are_on_the_shelf(): void
    {
        // Arrange — a new order, nothing drawn.
        $size = $this->sellableSize();
        [$order] = $this->order($size);
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->id}", [
            'city_id' => $order->city_id,
            'items' => [[
                'product_id' => $size->product_id,
                'product_variant_id' => $size->getKey(),
                'quantity' => '500',
            ]],
        ]);

        // Assert — the guard is about stock that has moved, not about editing as such.
        $response->assertOk();
        $this->assertSame('500.000', (string) $order->items()->sole()->quantity);
    }
}
