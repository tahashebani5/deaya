<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Vendor\Models\StockArrival;
use App\Domain\Vendor\Models\StockArrivalItem;
use App\Domain\Vendor\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Undoing a receipt somebody entered in error — «تسجيل استلام بالخطأ».
 *
 * The rule, in one line: **for 24 hours after the receipt was posted, and only while none of the
 * arriving stock has been touched.** Those two halves are deliberately different in kind, and
 * most of this file exists to pin the difference down:
 *
 * - the **24 hours** is policy, measured from the receipt and not from the purchase order, and
 *   `purchase_orders.reverse_receipt_any_time` waives it;
 * - **nothing drawn on** is arithmetic, and no grant reaches it — a manager on day one is
 *   refused exactly as flatly as a storekeeper on day three.
 *
 * Each success asserts the document, the order's own line quantities and status, the ledger row
 * each line produced, the cost layer it drew to nothing and the balance it left behind together.
 * Asserting one of those alone would miss the failure this feature exists to prevent — the same
 * reasoning `PurchaseOrderTest` documents for the receipt itself.
 *
 * Arrange - Act - Assert throughout.
 */
class PurchaseOrderReceiptReversalTest extends TestCase
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

    /** @return array<string, string> */
    private function orderManager(): array
    {
        return $this->auth(PermissionName::ViewPurchaseOrders, PermissionName::ManagePurchaseOrders);
    }

    /** The storekeeper: may post a receipt and undo one, within the window. @return array<string, string> */
    private function storekeeper(): array
    {
        return $this->auth(PermissionName::ViewInventory, PermissionName::ManageInventory);
    }

    /** The same, plus the grant that waives the clock. @return array<string, string> */
    private function overrideManager(): array
    {
        return $this->auth(
            PermissionName::ViewInventory,
            PermissionName::ManageInventory,
            PermissionName::ReverseReceiptAnyTime,
        );
    }

    /** See `PurchaseOrderTest::forgetAuth()` — call immediately before the request that needs it. */
    private function forgetAuth(): void
    {
        $this->app->get('auth')->forgetGuards();
    }

    private function balanceOf(Warehouse $warehouse, StockItem $item): string
    {
        return (string) (WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $item->id)
            ->first()?->quantity ?? '0.000');
    }

    /**
     * A purchase order for 10 of one size, received in full — the state every test here starts
     * from, because there is nothing to undo before it.
     *
     * @return array{order: array<string, mixed>, arrival: array<string, mixed>, vendor: Vendor, warehouse: Warehouse, item: StockItem}
     */
    private function receivedOrder(float $quantity = 10): array
    {
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $item = StockItem::factory()->create();

        $order = $this->withHeaders($this->orderManager())->postJson('/api/v1/purchase-orders', [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['stock_item_id' => $item->id, 'quantity_ordered' => 10, 'base_total_cost' => 50],
            ],
        ])->assertCreated()->json('data');

        $storekeeper = $this->storekeeper();
        $this->forgetAuth();

        $arrival = $this->withHeaders($storekeeper)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/arrivals",
            ['invoice_number' => 'INV-9001', 'items' => [
                ['stock_item_id' => $item->id, 'quantity' => $quantity],
            ]],
        )->assertCreated()->json('data');

        return compact('order', 'arrival', 'vendor', 'warehouse', 'item');
    }

    // ──────────────────────────────── the ordinary case ────────────────────────────────

    public function test_a_storekeeper_can_undo_a_receipt_entered_in_error_within_the_window(): void
    {
        // Arrange
        ['order' => $order, 'arrival' => $arrival, 'warehouse' => $warehouse, 'item' => $item] = $this->receivedOrder();
        $storekeeper = $this->storekeeper();
        $this->forgetAuth();

        // Act
        $response = $this->withHeaders($storekeeper)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/receipt-reversal",
            ['reason' => 'سُجّلت الكمية خطأً — ٥٠٠ بدل ٥٠'],
        );

        // Assert — the order is open again …
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.status', 'arrived');
        $this->assertDatabaseHas('purchase_orders', ['id' => $order['id'], 'status' => 'arrived']);

        // … its line owes everything again …
        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $order['id'],
            'stock_item_id' => $item->id,
            'quantity_received' => '0.000',
        ]);

        // … the document is kept and stamped rather than deleted …
        $this->assertDatabaseHas('stock_arrivals', [
            'id' => $arrival['id'],
            'reversal_reason' => 'سُجّلت الكمية خطأً — ٥٠٠ بدل ٥٠',
            'deleted_at' => null,
        ]);
        $this->assertNotNull($this->arrivalRow($arrival['id'])->reversed_at);

        // … a ledger row says where the stock went, pointing at the receipt it undoes …
        $received = $this->arrivalMovementId($arrival['id']);
        $this->assertDatabaseHas('stock_movements', [
            'stock_item_id' => $item->id,
            'from_warehouse_id' => $warehouse->id,
            'to_warehouse_id' => null,
            'quantity' => '10.000',
            'movement_type' => 'arrival_reversal',
            'reverses_movement_id' => $received,
            'reference_id' => $arrival['id'],
        ]);

        // … the layer it opened is drawn to nothing, with a consumption row explaining it …
        $batch = StockBatch::query()->where('stock_movement_id', $received)->sole();
        $this->assertSame('0.000', (string) $batch->quantity_remaining);
        $this->assertDatabaseHas('stock_batch_consumptions', [
            'stock_batch_id' => $batch->id,
            'quantity' => '10.000',
        ]);

        // … and the shelf is back where it started
        $this->assertSame('0.000', $this->balanceOf($warehouse, $item));
    }

    public function test_the_order_can_be_received_again_correctly_after_a_reversal(): void
    {
        // Arrange
        ['order' => $order, 'warehouse' => $warehouse, 'item' => $item] = $this->receivedOrder();
        $storekeeper = $this->storekeeper();
        $this->forgetAuth();
        $this->withHeaders($storekeeper)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/receipt-reversal",
            ['reason' => 'كمية خاطئة'],
        )->assertOk();

        // Act — the whole point of reopening it
        $response = $this->withHeaders($storekeeper)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/arrivals",
            ['items' => [['stock_item_id' => $item->id, 'quantity' => 4]]],
        );

        // Assert
        $response->assertCreated();
        $this->assertDatabaseHas('purchase_orders', ['id' => $order['id'], 'status' => 'completed']);
        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $order['id'],
            'stock_item_id' => $item->id,
            'quantity_received' => '4.000',
        ]);
        // Only the corrected shipment is on the shelf — the reversed one left nothing behind.
        $this->assertSame('4.000', $this->balanceOf($warehouse, $item));
        $this->assertDatabaseCount('stock_arrivals', 2);
    }

    // ──────────────────────────────── the window ────────────────────────────────

    /**
     * **Anchored to the receipt, never to the purchase order.** The order below is issued and
     * received on the same day only to keep the arrangement short; what moves the clock past the
     * deadline is time since the *arrival*, which is why travelling 25 hours from it is enough.
     */
    public function test_the_window_closes_twenty_four_hours_after_the_receipt(): void
    {
        // Arrange
        ['order' => $order, 'warehouse' => $warehouse, 'item' => $item] = $this->receivedOrder();
        $storekeeper = $this->storekeeper();
        $this->forgetAuth();
        $this->travel(25)->hours();

        // Act
        $response = $this->withHeaders($storekeeper)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/receipt-reversal",
            ['reason' => 'كمية خاطئة'],
        );

        // Assert — refused, and nothing moved
        $response->assertStatus(422)->assertJsonPath('status', false);
        $this->assertDatabaseHas('purchase_orders', ['id' => $order['id'], 'status' => 'completed']);
        $this->assertSame('10.000', $this->balanceOf($warehouse, $item));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_a_receipt_may_still_be_undone_at_the_edge_of_the_window(): void
    {
        // Arrange
        ['order' => $order, 'warehouse' => $warehouse, 'item' => $item] = $this->receivedOrder();
        $storekeeper = $this->storekeeper();
        $this->forgetAuth();
        $this->travel(23)->hours();

        // Act
        $response = $this->withHeaders($storekeeper)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/receipt-reversal",
            ['reason' => 'كمية خاطئة'],
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.status', 'arrived');
        $this->assertSame('0.000', $this->balanceOf($warehouse, $item));
    }

    public function test_a_manager_holding_the_override_may_step_past_the_window(): void
    {
        // Arrange
        ['order' => $order, 'warehouse' => $warehouse, 'item' => $item] = $this->receivedOrder();
        $manager = $this->overrideManager();
        $this->forgetAuth();
        $this->travel(3)->days();

        // Act
        $response = $this->withHeaders($manager)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/receipt-reversal",
            ['reason' => 'اكتُشف الخطأ في الجرد الشهري'],
        );

        // Assert — the whole unwind still happens, three days later
        $response->assertOk()->assertJsonPath('data.status', 'arrived');
        $this->assertSame('0.000', $this->balanceOf($warehouse, $item));
        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $order['id'],
            'stock_item_id' => $item->id,
            'quantity_received' => '0.000',
        ]);
    }

    // ──────────────────────────── the guard no grant waives ────────────────────────────

    /**
     * **The line between the two halves of the rule.** The override waives the clock; it does not
     * waive arithmetic. One unit off the shelf is enough — the cost of what left is already in
     * another movement's consumption rows, and no reversal reaches it.
     */
    public function test_a_manager_is_still_refused_once_any_of_the_stock_has_been_drawn_on(): void
    {
        // Arrange
        ['order' => $order, 'warehouse' => $warehouse, 'item' => $item] = $this->receivedOrder();
        $manager = $this->overrideManager();
        $this->forgetAuth();
        $this->withHeaders($manager)->postJson('/api/v1/stock-movements/adjustments', [
            'stock_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'direction' => 'decrease',
            'quantity' => 1,
            'adjustment_reason' => 'damage',
            'notes' => 'تلف وحدة واحدة',
        ])->assertCreated();

        // Act — well inside the window, and by the strongest grant there is
        $response = $this->withHeaders($manager)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/receipt-reversal",
            ['reason' => 'كمية خاطئة'],
        );

        // Assert — refused, and the refusal left no trace at all
        $response->assertStatus(422)->assertJsonPath('status', false);
        $this->assertDatabaseHas('purchase_orders', ['id' => $order['id'], 'status' => 'completed']);
        $this->assertSame('9.000', $this->balanceOf($warehouse, $item));
        $this->assertDatabaseMissing('stock_movements', ['movement_type' => 'arrival_reversal']);
        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $order['id'],
            'stock_item_id' => $item->id,
            'quantity_received' => '10.000',
        ]);
    }

    public function test_a_receipt_may_not_be_undone_twice(): void
    {
        // Arrange
        ['order' => $order, 'warehouse' => $warehouse, 'item' => $item] = $this->receivedOrder();
        $storekeeper = $this->storekeeper();
        $this->forgetAuth();
        $this->withHeaders($storekeeper)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/receipt-reversal",
            ['reason' => 'كمية خاطئة'],
        )->assertOk();

        // Act
        $response = $this->withHeaders($storekeeper)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/receipt-reversal",
            ['reason' => 'مرة أخرى'],
        );

        // Assert — refused, and the shelf was not halved a second time
        $response->assertStatus(422)->assertJsonPath('status', false);
        $this->assertSame('0.000', $this->balanceOf($warehouse, $item));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_undoing_a_receipt_on_an_order_that_never_had_one_is_refused(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $item = StockItem::factory()->create();
        $order = $this->withHeaders($this->orderManager())->postJson('/api/v1/purchase-orders', [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [['stock_item_id' => $item->id, 'quantity_ordered' => 10, 'base_total_cost' => 50]],
        ])->assertCreated()->json('data');
        $storekeeper = $this->storekeeper();
        $this->forgetAuth();

        // Act
        $response = $this->withHeaders($storekeeper)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/receipt-reversal",
            ['reason' => 'لا شيء لأتراجع عنه'],
        );

        // Assert
        $response->assertStatus(422)->assertJsonPath('status', false);
        $this->assertDatabaseHas('purchase_orders', ['id' => $order['id'], 'status' => 'new']);
    }

    // ──────────────────────────────── who, and with what ────────────────────────────────

    public function test_a_reason_is_required(): void
    {
        // Arrange
        ['order' => $order, 'warehouse' => $warehouse, 'item' => $item] = $this->receivedOrder();
        $storekeeper = $this->storekeeper();
        $this->forgetAuth();

        // Act
        $response = $this->withHeaders($storekeeper)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/receipt-reversal",
            [],
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('10.000', $this->balanceOf($warehouse, $item));
    }

    public function test_a_purchase_orders_manager_without_inventory_permission_may_not_undo_a_receipt(): void
    {
        // Arrange
        ['order' => $order, 'warehouse' => $warehouse, 'item' => $item] = $this->receivedOrder();
        $orderManager = $this->orderManager();
        $this->forgetAuth();

        // Act
        $response = $this->withHeaders($orderManager)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/receipt-reversal",
            ['reason' => 'كمية خاطئة'],
        );

        // Assert — drafting the paperwork never granted the right to move stock
        $response->assertForbidden();
        $this->assertSame('10.000', $this->balanceOf($warehouse, $item));
    }

    /**
     * Deliberately arranges nothing: authentication is settled by the middleware before the route
     * ever resolves an order, so a bare id is the honest fixture — and building a real one first
     * would leave an authenticated guard primed in the container, which no unauthenticated
     * request in a real HTTP cycle ever inherits.
     */
    public function test_undoing_a_receipt_needs_authentication(): void
    {
        // Act
        $response = $this->postJson(
            '/api/v1/purchase-orders/1/receipt-reversal',
            ['reason' => 'كمية خاطئة'],
        );

        // Assert
        $response->assertUnauthorized()->assertJsonPath('status', false);
    }

    /**
     * The invariant `StockBatchLedgerTest` exists to hold, checked across the one movement type
     * that draws a layer down without going through FIFO:
     *
     * > for every (warehouse, size), `SUM(stock_batches.quantity_remaining)` equals
     * > `warehouse_stocks.quantity`.
     *
     * Asserted after the reversal *and* after the corrected receipt that follows it, because a
     * withdrawal that forgot to shrink the balance would still look right at zero.
     */
    public function test_the_batch_ledger_invariant_holds_across_a_reversal(): void
    {
        // Arrange
        ['order' => $order, 'warehouse' => $warehouse, 'item' => $item] = $this->receivedOrder();
        $storekeeper = $this->storekeeper();
        $this->forgetAuth();

        // Act
        $this->withHeaders($storekeeper)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/receipt-reversal",
            ['reason' => 'كمية خاطئة'],
        )->assertOk();

        // Assert
        $this->assertSame($this->batchSum($warehouse, $item), $this->balanceOf($warehouse, $item));

        // … and again once the corrected shipment lands on the same shelf
        $this->withHeaders($storekeeper)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/arrivals",
            ['items' => [['stock_item_id' => $item->id, 'quantity' => 4]]],
        )->assertCreated();

        $this->assertSame('4.000', $this->balanceOf($warehouse, $item));
        $this->assertSame($this->batchSum($warehouse, $item), $this->balanceOf($warehouse, $item));
    }

    // ──────────────────────────────── what the screen reads ────────────────────────────────

    /**
     * The button is drawn from the *asking* user's grants, so the two callers below see different
     * answers about the same order on the same day — which is the whole reason it is published
     * rather than inferred from the status.
     */
    public function test_the_reversal_affordance_is_published_per_caller(): void
    {
        // Arrange
        ['order' => $order] = $this->receivedOrder();
        $storekeeper = $this->auth(PermissionName::ViewPurchaseOrders, PermissionName::ViewInventory);
        $manager = $this->auth(PermissionName::ViewPurchaseOrders, PermissionName::ReverseReceiptAnyTime);
        $this->travel(3)->days();
        $this->forgetAuth();

        // Act
        $toStorekeeper = $this->withHeaders($storekeeper)->getJson("/api/v1/purchase-orders/{$order['id']}");
        $this->forgetAuth();
        $toManager = $this->withHeaders($manager)->getJson("/api/v1/purchase-orders/{$order['id']}");

        // Assert
        $toStorekeeper->assertOk()->assertJsonPath('data.can_reverse_receipt', false);
        $toManager->assertOk()->assertJsonPath('data.can_reverse_receipt', true);
        // The deadline itself is a fact about the receipt, so both are told the same one.
        $this->assertNotNull($toStorekeeper->json('data.receipt_reversible_until'));
        $this->assertSame(
            $toStorekeeper->json('data.receipt_reversible_until'),
            $toManager->json('data.receipt_reversible_until'),
        );
    }

    /** What every cost layer on this shelf still holds, together. */
    private function batchSum(Warehouse $warehouse, StockItem $item): string
    {
        $total = '0';

        $remainders = StockBatch::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $item->id)
            ->pluck('quantity_remaining');

        foreach ($remainders as $remaining) {
            $total = bcadd($total, (string) $remaining, 3);
        }

        return $total;
    }

    private function arrivalRow(int $arrivalId): StockArrival
    {
        return StockArrival::query()->findOrFail($arrivalId);
    }

    private function arrivalMovementId(int $arrivalId): int
    {
        return (int) StockArrivalItem::query()
            ->where('stock_arrival_id', $arrivalId)
            ->value('stock_movement_id');
    }
}
