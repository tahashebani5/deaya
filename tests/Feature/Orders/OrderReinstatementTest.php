<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockMovement;
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
 * Undoing a cancellation made by mistake.
 *
 * > **The order goes back exactly where it stood, read from its own timeline — and no stock
 * > moves.**
 *
 * «إلغاء تام» stays final on the map: `OrderStatusTest` still asserts it leads nowhere, and this
 * endpoint is not a move on it but an undo of one recorded move. So the destination is never in
 * the payload, an order with no cancelling row in its timeline is refused rather than guessed
 * at, and the goods the cancellation credited back to the shelf stay there — the warehouse is
 * put right by hand.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderReinstatementTest extends TestCase
{
    use RefreshDatabase;

    private ?Warehouse $warehouse = null;

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
    private function auth(PermissionName ...$permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (PermissionName $p) => $p->value, $permissions));

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** Someone who may walk an order to the press, write it off, and say the write-off was wrong. */
    private function foreman(): array
    {
        return $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ManageOrders,
            PermissionName::MoveOrderToReadyToPrint,
            PermissionName::MoveOrderToPrinting,
            PermissionName::MoveOrderToReady,
            PermissionName::DispatchOrders,
            PermissionName::CancelOrders,
            PermissionName::ViewInventory,
            PermissionName::ManageInventory,
        );
    }

    /** One shelf, made once and reused, so a fixture is never the subject of the test. */
    private function warehouse(): Warehouse
    {
        return $this->warehouse ??= Warehouse::factory()->create();
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $fields
     */
    private function move(array $headers, Order $order, OrderStatus $to, ?string $reason = null, array $fields = []): TestResponse
    {
        return $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/status",
            array_filter([
                'status' => $to->value,
                'reason' => $reason,
                'fields' => $fields === [] ? null : $fields,
            ]),
        );
    }

    /**
     * An order standing at the press with its stock already off the shelf, then written off.
     *
     * @param  array<string, string>  $headers
     */
    private function cancelledAtThePress(array $headers): Order
    {
        $order = Order::factory()->create();

        $this->move($headers, $order, OrderStatus::ReadyToPrint, fields: ['warehouse_id' => $this->warehouse()->id])
            ->assertOk();
        $this->move($headers, $order->refresh(), OrderStatus::Cancelled, 'أُلغيت بالخطأ')->assertOk();

        return $order->refresh();
    }

    // ──────────────────────────────── what the undo does ─────────────────────────────────

    public function test_it_puts_the_order_back_in_the_status_it_was_cancelled_from(): void
    {
        // Arrange
        $headers = $this->foreman();
        $order = $this->cancelledAtThePress($headers);

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/reinstate", [
            'reason' => 'العميل استلمها فعلاً',
        ]);

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.status', OrderStatus::ReadyToPrint->value)
            ->assertJsonPath('data.status_label', OrderStatus::ReadyToPrint->label())
            ->assertJsonPath('data.is_final', false);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::ReadyToPrint->value,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ]);
    }

    public function test_the_cancellation_stays_in_the_timeline_and_the_undo_is_written_above_it(): void
    {
        // Arrange
        $headers = $this->foreman();
        $order = $this->cancelledAtThePress($headers);

        // Act
        $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/reinstate", ['reason' => 'تراجع'])
            ->assertOk();

        // Assert — the write-off is history, not something the undo erased
        $this->assertDatabaseHas('order_status_transitions', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::ReadyToPrint->value,
            'to_status' => OrderStatus::Cancelled->value,
            'reason' => 'أُلغيت بالخطأ',
        ]);

        $this->assertDatabaseHas('order_status_transitions', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::Cancelled->value,
            'to_status' => OrderStatus::ReadyToPrint->value,
            'reason' => 'تراجع',
        ]);
    }

    public function test_the_note_is_optional(): void
    {
        // Arrange
        $headers = $this->foreman();
        $order = $this->cancelledAtThePress($headers);

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/reinstate");

        // Assert — the cancellation it undoes already carries the sentence that matters
        $response->assertOk()->assertJsonPath('data.status', OrderStatus::ReadyToPrint->value);
        $this->assertDatabaseHas('order_status_transitions', [
            'order_id' => $order->id,
            'to_status' => OrderStatus::ReadyToPrint->value,
            'from_status' => OrderStatus::Cancelled->value,
            'reason' => null,
        ]);
    }

    public function test_a_second_cancellation_is_undone_to_where_that_one_started(): void
    {
        // Arrange — written off at the press, put back, walked on, written off again
        $headers = $this->foreman();
        $order = $this->cancelledAtThePress($headers);

        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/reinstate")->assertOk();
        $this->move($headers, $order->refresh(), OrderStatus::Printing)->assertOk();
        $this->move($headers, $order->refresh(), OrderStatus::Cancelled, 'ثانية بالخطأ')->assertOk();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/reinstate");

        // Assert — the move being undone is the most recent one, not the first
        $response->assertOk()->assertJsonPath('data.status', OrderStatus::Printing->value);
    }

    // ──────────────────────────────────── the stock ──────────────────────────────────────

    public function test_it_does_not_take_the_stock_back_off_the_shelf(): void
    {
        // Arrange — a real shelf, drawn down by the handover and credited back by the write-off
        $headers = $this->foreman();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $warehouse = $this->warehouse();

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->stock_item_id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 100,
            'unit_cost' => 5,
        ])->assertCreated();

        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => '40',
        ]);

        $this->move($headers, $order, OrderStatus::ReadyToPrint, fields: ['warehouse_id' => $warehouse->id])
            ->assertOk();
        $this->move($headers, $order->refresh(), OrderStatus::Cancelled, 'بالخطأ')->assertOk();

        $balanceAfterCancelling = $this->balanceOf($warehouse, $variant);
        $movementsAfterCancelling = StockMovement::query()->count();
        $this->assertSame('100.000', $balanceAfterCancelling);

        // Act
        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/reinstate")->assertOk();

        // Assert — the shelf is exactly as the cancellation left it, and nothing was posted
        $this->assertSame($balanceAfterCancelling, $this->balanceOf($warehouse, $variant));
        $this->assertSame($movementsAfterCancelling, StockMovement::query()->count());
    }

    public function test_it_leaves_stock_deducted_at_standing_so_nothing_deducts_twice(): void
    {
        // Arrange
        $headers = $this->foreman();
        $order = $this->cancelledAtThePress($headers);
        $deductedAt = $order->stock_deducted_at;
        $this->assertNotNull($deductedAt);

        // Act
        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/reinstate")->assertOk();

        // Assert — it records that stock did leave this order once, which is still true
        $this->assertTrue($deductedAt->equalTo($order->refresh()->stock_deducted_at));
    }

    // ──────────────────────────────────── refusals ───────────────────────────────────────

    public function test_an_order_that_is_not_cancelled_is_refused(): void
    {
        // Arrange
        $headers = $this->foreman();
        $order = Order::factory()->create();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/reinstate");

        // Assert
        $response->assertStatus(422)->assertJsonPath('status', false);
        $this->assertSame(OrderStatus::New, $order->refresh()->status);
    }

    public function test_a_cancellation_with_nothing_in_the_timeline_is_refused_rather_than_guessed_at(): void
    {
        // Arrange — the shape an order imported before the timeline existed has
        $headers = $this->foreman();
        $order = Order::factory()->create([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => 'مستوردة',
        ]);
        $order->transitions()->forceDelete();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/reinstate");

        // Assert
        $response->assertStatus(422)->assertJsonPath('status', false);
        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
    }

    public function test_a_note_longer_than_the_column_is_refused(): void
    {
        // Arrange
        $headers = $this->foreman();
        $order = $this->cancelledAtThePress($headers);

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/reinstate", [
            'reason' => str_repeat('ا', 1001),
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
    }

    public function test_it_needs_the_cancellation_grant(): void
    {
        // Arrange — everything the walk needs, and not the one grant this endpoint costs
        $headers = $this->foreman();
        $order = $this->cancelledAtThePress($headers);

        $without = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ManageOrders,
            PermissionName::MoveOrderToPrinting,
        );

        // Act
        $response = $this->withHeaders($without)->postJson("/api/v1/orders/{$order->id}/reinstate");

        // Assert
        $response->assertForbidden();
        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
    }

    public function test_it_needs_a_signed_in_user(): void
    {
        // Arrange — **and the flush is the arrangement.** `withHeaders()` merges into
        // `$this->defaultHeaders` and they stand for the rest of the test, so the foreman's
        // bearer token that walked this order to «إلغاء تام» would still be attached to the
        // "unauthenticated" request below — which is why this test answered 200 instead of 401
        // while asserting nothing at all.
        $order = $this->cancelledAtThePress($this->foreman());

        $this->flushHeaders();

        // Act
        $response = $this->postJson("/api/v1/orders/{$order->id}/reinstate");

        // Assert
        $response->assertUnauthorized();
    }

    // ────────────────────────── what the order tells the app ─────────────────────────────

    public function test_a_cancelled_order_names_where_the_undo_would_put_it(): void
    {
        // Arrange
        $headers = $this->foreman();
        $order = $this->cancelledAtThePress($headers);

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert — the status itself, so the button can say it rather than ask somebody to tap
        $response->assertOk()
            ->assertJsonPath('data.reinstate_to', OrderStatus::ReadyToPrint->value)
            ->assertJsonPath('data.reinstate_to_label', OrderStatus::ReadyToPrint->label());
    }

    public function test_a_reader_without_the_grant_is_offered_no_undo(): void
    {
        // Arrange
        $order = $this->cancelledAtThePress($this->foreman());
        $reader = $this->auth(PermissionName::ViewOrders);

        // Act
        $response = $this->withHeaders($reader)->getJson("/api/v1/orders/{$order->id}");

        // Assert
        $response->assertOk()->assertJsonPath('data.reinstate_to', null);
    }

    public function test_an_order_that_is_not_cancelled_is_offered_no_undo(): void
    {
        // Arrange
        $headers = $this->foreman();
        $order = Order::factory()->create();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert
        $response->assertOk()->assertJsonPath('data.reinstate_to', null);
    }

    private function balanceOf(Warehouse $warehouse, ProductVariant $variant): string
    {
        return (string) (WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $variant->stock_item_id)
            ->first()?->quantity ?? '0.000');
    }
}
