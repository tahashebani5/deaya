<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\PurchaseOrder\Actions\SendPurchaseOrder;
use App\Domain\PurchaseOrder\Exceptions\PurchaseOrderNeedsAtLeastOneItem;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\Vendor\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Purchase orders — stock ordered from a vendor ahead of it arriving: `new → arrived →
 * completed`, with `cancelled` reachable from either open status.
 *
 * Drafting, editing, sending and cancelling are guarded by `purchase_orders.*`; receiving a
 * shipment against one is guarded by `inventory.*` instead, the same pair `stock-arrivals`
 * already sits behind — several tests below exist specifically to pin that split down.
 *
 * The receiving tests assert the document, the order's own line quantities and status, the
 * ledger row each line produced, and the balance it left behind together — asserting only one of
 * those would miss the failure mode this feature exists to prevent, the same reasoning
 * `StockArrivalTest` documents for a plain arrival.
 *
 * Arrange - Act - Assert throughout.
 */
class PurchaseOrderTest extends TestCase
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
    private function viewer(): array
    {
        return $this->auth(PermissionName::ViewPurchaseOrders);
    }

    /** @return array<string, string> */
    private function manager(): array
    {
        return $this->auth(PermissionName::ViewPurchaseOrders, PermissionName::ManagePurchaseOrders);
    }

    /** @return array<string, string> */
    private function inventoryManager(): array
    {
        return $this->auth(PermissionName::ViewInventory, PermissionName::ManageInventory);
    }

    /** Authenticated, but granted nothing at all. @return array<string, string> */
    private function outsider(): array
    {
        return $this->auth();
    }

    /**
     * Drop the resolved user from the auth guards.
     *
     * The container is reused across requests inside one test, so once a guard has
     * authenticated somebody it keeps returning them — a later request carrying a different
     * user's token would still be treated as the first. Real requests each boot a fresh
     * container; clearing the guards is what makes a test that switches users honest. Needed
     * throughout this file because most tests here genuinely need two different callers — one
     * to draft/send the order, another to receive against it.
     *
     * **Call this last, immediately before the request that needs the new identity — never
     * before building that identity's headers.** Every helper above creates a `User`, and
     * `User` (like every audited model) writes an activity-log entry on save; resolving *that*
     * entry's causer touches the guard too. Doing that after this call re-primes the guard from
     * whatever request is still bound in the container at that moment — which, outside a fresh
     * HTTP cycle, is the *previous* request — silently undoing the reset before the real request
     * ever goes out.
     */
    private function forgetAuth(): void
    {
        $this->app->get('auth')->forgetGuards();
    }

    private function variant(): StockItem
    {
        return StockItem::factory()->create();
    }

    private function stockOf(Warehouse $warehouse, StockItem $variant): ?WarehouseStock
    {
        return WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $variant->id)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Vendor $vendor, Warehouse $warehouse, StockItem $variant, array $overrides = []): array
    {
        return array_merge([
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
            'expected_date' => now()->addWeek()->toDateString(),
            'notes' => 'دفعة أولى',
            'items' => [
                ['stock_item_id' => $variant->id, 'quantity_ordered' => 10, 'base_total_cost' => 50],
            ],
        ], $overrides);
    }

    // ──────────────────────────────── listing ────────────────────────────────

    public function test_a_user_with_view_permission_can_list_purchase_orders(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        PurchaseOrder::factory()->from($vendor)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/purchase-orders');

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'status', 'message',
                'data' => [['id', 'vendor_id', 'warehouse_id', 'status', 'status_label', 'order_date', 'items']],
                'meta' => ['current_page', 'per_page', 'last_page', 'total'],
            ]);
    }

    public function test_listing_purchase_orders_needs_authentication(): void
    {
        // Act
        $response = $this->getJson('/api/v1/purchase-orders');

        // Assert
        $response->assertUnauthorized()->assertJsonPath('status', false);
    }

    public function test_a_user_granted_nothing_may_not_list_purchase_orders(): void
    {
        // Arrange
        PurchaseOrder::factory()->create();
        $headers = $this->outsider();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/purchase-orders');

        // Assert
        $response->assertForbidden()->assertJsonPath('status', false);
    }

    public function test_the_purchase_order_list_is_paginated(): void
    {
        // Arrange
        PurchaseOrder::factory()->count(20)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/purchase-orders?per_page=5&page=2');

        // Assert
        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 20);
    }

    public function test_the_list_can_be_filtered_by_vendor(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        PurchaseOrder::factory()->from($vendor)->create();
        PurchaseOrder::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/purchase-orders?vendor_id={$vendor->id}");

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.vendor_id', $vendor->id);
    }

    public function test_the_list_can_be_filtered_by_warehouse(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        PurchaseOrder::factory()->into($warehouse)->create();
        PurchaseOrder::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/purchase-orders?warehouse_id={$warehouse->id}");

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.warehouse_id', $warehouse->id);
    }

    public function test_the_list_can_be_searched_by_vendor_name(): void
    {
        // Arrange — the name is the biggest thing on a purchase-order card, so it is the thing
        // somebody types when they are looking for one.
        $vendor = Vendor::factory()->create(['name' => 'شركة محمد بن عبد العزيز للأوراق']);
        PurchaseOrder::factory()->from($vendor)->create();
        PurchaseOrder::factory()->from(Vendor::factory()->create(['name' => 'مطابع طرابلس']))->create();
        $headers = $this->viewer();

        // Act — a fragment from the middle, which is how people actually search.
        $response = $this->withHeaders($headers)->getJson('/api/v1/purchase-orders?search='.urlencode('عبد العزيز'));

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.vendor_id', $vendor->id);
    }

    public function test_the_list_can_be_searched_by_warehouse_name(): void
    {
        // Arrange — the other name on the card.
        $warehouse = Warehouse::factory()->create(['name' => 'مخزن ولي العهد']);
        PurchaseOrder::factory()->into($warehouse)->create();
        PurchaseOrder::factory()->into(Warehouse::factory()->create(['name' => 'المخزن الرئيسي']))->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/purchase-orders?search='.urlencode('ولي العهد'));

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.warehouse_id', $warehouse->id);
    }

    public function test_a_number_is_searched_as_the_orders_own_id(): void
    {
        // Arrange — the detail screen calls it «أمر شراء #12», so «12» has to find it.
        $order = PurchaseOrder::factory()->create();
        PurchaseOrder::factory()->count(2)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/purchase-orders?search={$order->id}");

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $order->id);
    }

    public function test_the_search_narrows_the_status_filter_rather_than_replacing_it(): void
    {
        // Arrange — the filter sheet and the search box are two questions about one list, and a
        // screen showing a cancelled order under «جديد» would be answering only the last one asked.
        $vendor = Vendor::factory()->create(['name' => 'مطابع الجنوب']);
        PurchaseOrder::factory()->from($vendor)->create();
        PurchaseOrder::factory()->from($vendor)->cancelled()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/purchase-orders?status=cancelled&search='.urlencode('الجنوب'));

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'cancelled');
    }

    public function test_a_blank_search_is_not_a_filter(): void
    {
        // Arrange — clearing the box sends `search=`, which means «show me everything» and must
        // not be read as «find the orders whose vendor is called nothing».
        PurchaseOrder::factory()->count(2)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/purchase-orders?search=');

        // Assert
        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_summary_counts_only_what_the_search_left_on_screen(): void
    {
        // Arrange — the counts sit on the same filters the list uses, so a sheet reading «جديد ٩»
        // over a searched list of one would be a number that disagrees with the screen it opens.
        $vendor = Vendor::factory()->create(['name' => 'مطابع الزاوية']);
        PurchaseOrder::factory()->from($vendor)->create();
        PurchaseOrder::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/purchase-orders/summary?search='.urlencode('الزاوية'));

        // Assert
        $response->assertOk()->assertJsonPath('data.counts.new', 1)->assertJsonPath('data.total', 1);
    }

    public function test_the_list_can_be_filtered_by_status(): void
    {
        // Arrange
        PurchaseOrder::factory()->create();
        PurchaseOrder::factory()->cancelled()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/purchase-orders?status=cancelled');

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'cancelled');
    }

    public function test_the_list_can_be_filtered_by_more_than_one_status(): void
    {
        // Arrange
        PurchaseOrder::factory()->create();
        PurchaseOrder::factory()->arrived()->create();
        PurchaseOrder::factory()->completed()->create();
        PurchaseOrder::factory()->cancelled()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/purchase-orders?status[]=new&status[]=arrived');

        // Assert
        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(
            ['new', 'arrived'],
            array_column($response->json('data'), 'status'),
        );
    }

    public function test_a_status_the_machine_does_not_know_is_ignored_rather_than_refused(): void
    {
        // Arrange
        PurchaseOrder::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/purchase-orders?status=whatever');

        // Assert
        // Dropped, not obeyed: a filter nobody can satisfy would answer with an empty page and
        // read as «no purchase orders» rather than «you asked for something that does not exist».
        $response->assertOk()->assertJsonCount(1, 'data');
    }

    // ──────────────────────────────── the summary ────────────────────────────────

    public function test_the_summary_counts_purchase_orders_in_every_status(): void
    {
        // Arrange
        PurchaseOrder::factory()->count(2)->create();
        PurchaseOrder::factory()->arrived()->create();
        PurchaseOrder::factory()->completed()->count(3)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/purchase-orders/summary');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.counts.new', 2)
            ->assertJsonPath('data.counts.arrived', 1)
            ->assertJsonPath('data.counts.completed', 3)
            // Present as a zero rather than absent: zero is an answer, a missing key is «we did
            // not ask».
            ->assertJsonPath('data.counts.cancelled', 0)
            ->assertJsonPath('data.total', 6);
    }

    public function test_the_summary_can_be_narrowed_to_one_vendor(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        PurchaseOrder::factory()->from($vendor)->create();
        PurchaseOrder::factory()->from($vendor)->completed()->create();
        PurchaseOrder::factory()->completed()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson("/api/v1/purchase-orders/summary?vendor_id={$vendor->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.counts.new', 1)
            ->assertJsonPath('data.counts.completed', 1)
            ->assertJsonPath('data.total', 2);
    }

    public function test_the_summary_ignores_the_status_filter_it_is_counting(): void
    {
        // Arrange
        PurchaseOrder::factory()->create();
        PurchaseOrder::factory()->cancelled()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/purchase-orders/summary?status=cancelled');

        // Assert
        // Counts narrowed by the status being counted would every one of them equal the list's
        // own length — the same reasoning `OrderStatusCountsQuery` documents.
        $response->assertOk()->assertJsonPath('data.total', 2)->assertJsonPath('data.counts.new', 1);
    }

    public function test_a_soft_deleted_purchase_order_is_absent_from_the_summary(): void
    {
        // Arrange
        PurchaseOrder::factory()->create();
        PurchaseOrder::factory()->create()->delete();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/purchase-orders/summary');

        // Assert
        $response->assertOk()->assertJsonPath('data.counts.new', 1)->assertJsonPath('data.total', 1);
    }

    public function test_the_summary_is_not_read_as_a_purchase_order_id(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/purchase-orders/summary');

        // Assert
        // The route has to be declared before `{purchase_order}`, or implicit binding tries to
        // resolve the word «summary» as an id and answers 404 — the same trap `orders/summary`
        // carries a comment about in `api.php`.
        $response->assertOk();
    }

    public function test_a_user_granted_nothing_may_not_read_the_summary(): void
    {
        // Arrange
        PurchaseOrder::factory()->create();
        $headers = $this->outsider();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/purchase-orders/summary');

        // Assert
        $response->assertForbidden()->assertJsonPath('status', false);
    }

    public function test_reading_the_summary_needs_authentication(): void
    {
        // Act
        $response = $this->getJson('/api/v1/purchase-orders/summary');

        // Assert
        $response->assertUnauthorized()->assertJsonPath('status', false);
    }

    public function test_a_soft_deleted_purchase_order_is_absent_from_the_list(): void
    {
        // Arrange
        PurchaseOrder::factory()->create();
        PurchaseOrder::factory()->create()->delete();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/purchase-orders');

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data');
    }

    // ──────────────────────────────── reading one ────────────────────────────────

    public function test_a_viewer_can_read_one_purchase_order_with_its_items(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();
        $created = $this->withHeaders($headers)->postJson(
            '/api/v1/purchase-orders',
            $this->payload($vendor, $warehouse, $variant),
        )->json('data');
        $viewerHeaders = $this->viewer();
        $this->forgetAuth();

        // Act
        $response = $this->withHeaders($viewerHeaders)->getJson("/api/v1/purchase-orders/{$created['id']}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.id', $created['id'])
            ->assertJsonPath('data.status', 'new')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity_ordered', '10.000')
            ->assertJsonPath('data.items.0.quantity_received', '0.000')
            ->assertJsonPath('data.items.0.quantity_remaining', '10.000')
            ->assertJsonPath('data.items.0.quantity_over_received', '0.000');
    }

    public function test_reading_a_purchase_order_that_does_not_exist_is_a_404(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/purchase-orders/999999');

        // Assert
        $response->assertNotFound()->assertJsonPath('status', false);
    }

    // ──────────────────────────────── creating ────────────────────────────────

    public function test_a_manager_can_create_a_purchase_order(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            '/api/v1/purchase-orders',
            $this->payload($vendor, $warehouse, $variant),
        );

        // Assert
        $response->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.vendor_id', $vendor->id)
            ->assertJsonPath('data.warehouse_id', $warehouse->id)
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.items.0.stock_item_id', $variant->id)
            ->assertJsonPath('data.items.0.quantity_ordered', '10.000');

        $this->assertDatabaseHas('purchase_orders', ['vendor_id' => $vendor->id, 'status' => 'new']);
        $this->assertDatabaseHas('purchase_order_items', [
            'stock_item_id' => $variant->id,
            'quantity_ordered' => '10.000',
            'quantity_received' => '0.000',
        ]);
    }

    public function test_a_viewer_may_not_create_a_purchase_order(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            '/api/v1/purchase-orders',
            $this->payload($vendor, $warehouse, $variant),
        );

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_creating_a_purchase_order_requires_vendor_warehouse_order_date_and_items(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/purchase-orders', []);

        // Assert
        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonStructure(['errors' => ['vendor_id', 'warehouse_id', 'order_date', 'items']]);
    }

    public function test_a_vendor_that_does_not_exist_is_refused(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/purchase-orders', [
            'vendor_id' => 999999,
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [['stock_item_id' => $variant->id, 'quantity_ordered' => 10, 'base_total_cost' => 50]],
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['vendor_id']]);
    }

    public function test_duplicate_product_variants_in_the_items_are_refused(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/purchase-orders', $this->payload(
            $vendor,
            $warehouse,
            $variant,
            ['items' => [
                ['stock_item_id' => $variant->id, 'quantity_ordered' => 5, 'base_total_cost' => 25],
                ['stock_item_id' => $variant->id, 'quantity_ordered' => 3, 'base_total_cost' => 15],
            ]],
        ));

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['items.0.stock_item_id']]);
    }

    // ──────────────────────────────── updating ────────────────────────────────

    public function test_a_manager_can_update_a_purchase_order_while_new(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();
        $order = $this->withHeaders($headers)->postJson(
            '/api/v1/purchase-orders',
            $this->payload($vendor, $warehouse, $variant),
        )->json('data');

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/purchase-orders/{$order['id']}",
            $this->payload($vendor, $warehouse, $variant, ['notes' => 'ملاحظة محدثة']),
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.notes', 'ملاحظة محدثة');
        $this->assertDatabaseHas('purchase_orders', ['id' => $order['id'], 'notes' => 'ملاحظة محدثة']);
    }

    public function test_updating_syncs_items_by_id_updating_creating_and_removing(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $kept = $this->variant();
        $removed = $this->variant();
        $added = $this->variant();
        $headers = $this->manager();

        $order = $this->withHeaders($headers)->postJson('/api/v1/purchase-orders', $this->payload(
            $vendor,
            $warehouse,
            $kept,
            ['items' => [
                ['stock_item_id' => $kept->id, 'quantity_ordered' => 10, 'base_total_cost' => 50],
                ['stock_item_id' => $removed->id, 'quantity_ordered' => 4, 'base_total_cost' => 20],
            ]],
        ))->json('data');

        $keptItemId = collect($order['items'])->firstWhere('stock_item_id', $kept->id)['id'];

        // Act — kept item's quantity changes, removed item is dropped, added item is new
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/purchase-orders/{$order['id']}",
            $this->payload($vendor, $warehouse, $kept, ['items' => [
                ['id' => $keptItemId, 'stock_item_id' => $kept->id, 'quantity_ordered' => 20, 'base_total_cost' => 100],
                ['stock_item_id' => $added->id, 'quantity_ordered' => 7, 'base_total_cost' => 35],
            ]]),
        );

        // Assert
        $response->assertOk()->assertJsonCount(2, 'data.items');
        $this->assertDatabaseHas('purchase_order_items', ['id' => $keptItemId, 'quantity_ordered' => '20.000']);
        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $order['id'], 'stock_item_id' => $added->id, 'quantity_ordered' => '7.000',
        ]);
        $this->assertSoftDeleted('purchase_order_items', ['purchase_order_id' => $order['id'], 'stock_item_id' => $removed->id]);
    }

    public function test_updating_a_purchase_order_is_refused_once_it_has_arrived(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();
        $order = $this->withHeaders($headers)->postJson(
            '/api/v1/purchase-orders',
            $this->payload($vendor, $warehouse, $variant),
        )->json('data');
        $this->withHeaders($headers)->patchJson("/api/v1/purchase-orders/{$order['id']}/status", ['status' => 'arrived']);

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/purchase-orders/{$order['id']}",
            $this->payload($vendor, $warehouse, $variant, ['notes' => 'محاولة تعديل']),
        );

        // Assert
        $response->assertStatus(422)->assertJsonPath('status', false);
        $this->assertDatabaseMissing('purchase_orders', ['id' => $order['id'], 'notes' => 'محاولة تعديل']);
    }

    public function test_a_viewer_may_not_update_a_purchase_order(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $order = PurchaseOrder::factory()->from($vendor)->into($warehouse)->create();

        // Act
        $response = $this->withHeaders($this->viewer())->putJson(
            "/api/v1/purchase-orders/{$order->id}",
            $this->payload($vendor, $warehouse, $variant),
        );

        // Assert
        $response->assertForbidden();
    }

    // ──────────────────────────────── status changes ────────────────────────────────

    public function test_a_manager_can_send_a_purchase_order(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();
        $order = $this->withHeaders($headers)->postJson(
            '/api/v1/purchase-orders',
            $this->payload($vendor, $warehouse, $variant),
        )->json('data');

        // Act
        $response = $this->withHeaders($headers)->patchJson(
            "/api/v1/purchase-orders/{$order['id']}/status",
            ['status' => 'arrived'],
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.status', 'arrived');
        $this->assertDatabaseHas('purchase_orders', ['id' => $order['id'], 'status' => 'arrived']);
    }

    public function test_sending_a_purchase_order_twice_is_refused(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();
        $order = $this->withHeaders($headers)->postJson(
            '/api/v1/purchase-orders',
            $this->payload($vendor, $warehouse, $variant),
        )->json('data');
        $this->withHeaders($headers)->patchJson("/api/v1/purchase-orders/{$order['id']}/status", ['status' => 'arrived']);

        // Act
        $response = $this->withHeaders($headers)->patchJson(
            "/api/v1/purchase-orders/{$order['id']}/status",
            ['status' => 'arrived'],
        );

        // Assert
        $response->assertStatus(422)->assertJsonPath('status', false);
    }

    public function test_sending_a_purchase_order_with_no_items_is_refused(): void
    {
        // Arrange — unreachable through the HTTP API (creating and updating both require at
        // least one item), so this exercises the domain guard directly, the same way
        // ErrorHandlingTest calls SyncCustomerShops directly to reach a state the API cannot
        // produce.
        $order = PurchaseOrder::factory()->create();
        $send = app(SendPurchaseOrder::class);

        // Assert
        $this->expectException(PurchaseOrderNeedsAtLeastOneItem::class);

        // Act
        $send($order);
    }

    public function test_the_status_endpoint_rejects_completed_as_a_manual_target(): void
    {
        // Arrange
        $order = PurchaseOrder::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->patchJson(
            "/api/v1/purchase-orders/{$order->id}/status",
            ['status' => 'completed'],
        );

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_a_manager_can_cancel_a_new_purchase_order(): void
    {
        // Arrange
        $order = PurchaseOrder::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->patchJson(
            "/api/v1/purchase-orders/{$order->id}/status",
            ['status' => 'cancelled'],
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_a_manager_can_cancel_an_arrived_purchase_order(): void
    {
        // Arrange
        $order = PurchaseOrder::factory()->arrived()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->patchJson(
            "/api/v1/purchase-orders/{$order->id}/status",
            ['status' => 'cancelled'],
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cancelling_a_completed_purchase_order_is_refused(): void
    {
        // Arrange
        $order = PurchaseOrder::factory()->completed()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->patchJson(
            "/api/v1/purchase-orders/{$order->id}/status",
            ['status' => 'cancelled'],
        );

        // Assert
        $response->assertStatus(422)->assertJsonPath('status', false);
    }

    public function test_a_viewer_may_not_change_a_purchase_orders_status(): void
    {
        // Arrange
        $order = PurchaseOrder::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->patchJson(
            "/api/v1/purchase-orders/{$order->id}/status",
            ['status' => 'arrived'],
        );

        // Assert
        $response->assertForbidden();
    }

    // ──────────────────────────────── receiving ────────────────────────────────

    public function test_an_inventory_manager_can_fully_receive_a_new_purchase_order_in_one_shipment(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $order = $this->withHeaders($this->manager())->postJson(
            '/api/v1/purchase-orders',
            $this->payload($vendor, $warehouse, $variant),
        )->json('data');
        $inventoryHeaders = $this->inventoryManager();
        $this->forgetAuth();

        // Act
        $response = $this->withHeaders($inventoryHeaders)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/arrivals",
            ['invoice_number' => 'INV-2001', 'items' => [
                ['stock_item_id' => $variant->id, 'quantity' => 10],
            ]],
        );

        // Assert — the document …
        $response->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.vendor_id', $vendor->id)
            ->assertJsonPath('data.warehouse_id', $warehouse->id)
            ->assertJsonPath('data.purchase_order_id', $order['id'])
            ->assertJsonPath('data.invoice_number', 'INV-2001');

        // … the order itself, now fully received and completed …
        $this->assertDatabaseHas('purchase_orders', ['id' => $order['id'], 'status' => 'completed']);
        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $order['id'],
            'stock_item_id' => $variant->id,
            'quantity_received' => '10.000',
        ]);

        // … the ledger row it produced …
        $arrivalId = $response->json('data.id');
        $this->assertDatabaseHas('stock_movements', [
            'stock_item_id' => $variant->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => '10.000',
            'movement_type' => 'purchase_arrival',
            'reference_id' => $arrivalId,
        ]);

        // … and the balance it left behind
        $this->assertSame('10.000', (string) $this->stockOf($warehouse, $variant)?->quantity);
    }

    /**
     * **A short delivery closes the order too** — «تسجيل شحنات دوما يخليها مكتملة حتى لو في نواقص
     * وتسجل كنواقص», the owner on 2026-09-06. The 6 that never turned up are the supplier's
     * failing, not an open question about this order, so they are reported on the line as
     * `quantity_remaining` while the status moves on.
     */
    public function test_receiving_less_than_ordered_completes_the_order_and_reports_the_shortfall(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $order = $this->withHeaders($this->manager())->postJson(
            '/api/v1/purchase-orders',
            $this->payload($vendor, $warehouse, $variant),
        )->json('data');
        $inventoryHeaders = $this->inventoryManager();
        $viewerHeaders = $this->viewer();
        $this->forgetAuth();

        // Act
        $response = $this->withHeaders($inventoryHeaders)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/arrivals",
            ['items' => [['stock_item_id' => $variant->id, 'quantity' => 4]]],
        );

        // Assert
        $response->assertCreated();
        $this->assertDatabaseHas('purchase_orders', ['id' => $order['id'], 'status' => 'completed']);
        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $order['id'], 'stock_item_id' => $variant->id, 'quantity_received' => '4.000',
        ]);
        // … and only what actually turned up reached the shelf
        $this->assertSame('4.000', (string) $this->stockOf($warehouse, $variant)?->quantity);

        // … with the نواقص readable off the line rather than inferred from the status
        $this->forgetAuth();
        $this->withHeaders($viewerHeaders)->getJson("/api/v1/purchase-orders/{$order['id']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.items.0.quantity_ordered', '10.000')
            ->assertJsonPath('data.items.0.quantity_received', '4.000')
            ->assertJsonPath('data.items.0.quantity_remaining', '6.000')
            ->assertJsonPath('data.items.0.quantity_over_received', '0.000');
    }

    /**
     * The other half of the rule above: closing on a short delivery means closing for good. A
     * second truck is not a second receipt against this order — it is its own arrival, posted
     * through `POST /stock-arrivals` with no purchase order attached.
     */
    public function test_a_second_shipment_after_a_short_delivery_is_refused(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->inventoryManager();
        $order = $this->withHeaders($this->manager())->postJson(
            '/api/v1/purchase-orders',
            $this->payload($vendor, $warehouse, $variant),
        )->json('data');
        $this->forgetAuth();
        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/arrivals",
            ['items' => [['stock_item_id' => $variant->id, 'quantity' => 4]]],
        );

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/arrivals",
            ['items' => [['stock_item_id' => $variant->id, 'quantity' => 6]]],
        );

        // Assert
        $response->assertStatus(422)->assertJsonPath('status', false);
        $this->assertDatabaseHas('purchase_orders', ['id' => $order['id'], 'status' => 'completed']);
        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $order['id'], 'stock_item_id' => $variant->id, 'quantity_received' => '4.000',
        ]);
        $this->assertDatabaseCount('stock_arrivals', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('4.000', (string) $this->stockOf($warehouse, $variant)?->quantity);
    }

    public function test_receiving_needs_authentication(): void
    {
        // Arrange
        $order = PurchaseOrder::factory()->create();

        // Act
        $response = $this->postJson("/api/v1/purchase-orders/{$order->id}/arrivals", []);

        // Assert
        $response->assertUnauthorized();
    }

    public function test_a_purchase_orders_manager_without_inventory_permission_may_not_receive_a_shipment(): void
    {
        // Arrange — purchase_orders.manage alone is deliberately not enough; see the class docblock.
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $order = $this->withHeaders($this->manager())->postJson(
            '/api/v1/purchase-orders',
            $this->payload($vendor, $warehouse, $variant),
        )->json('data');
        $secondManagerHeaders = $this->manager();
        $this->forgetAuth();

        // Act — purchase_orders.manage alone, no inventory.manage
        $response = $this->withHeaders($secondManagerHeaders)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/arrivals",
            ['items' => [['stock_item_id' => $variant->id, 'quantity' => 10]]],
        );

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseCount('stock_arrivals', 0);
    }

    /**
     * A supplier who sends more of one size than was ordered has still sent it, and the shelf
     * holds what turned up — so the surplus is booked in rather than refused. The order closes
     * on it, and the surplus is reported in its own right rather than as a negative remainder.
     */
    public function test_over_receiving_is_accepted_and_the_surplus_lands_in_stock(): void
    {
        // Arrange — 10 ordered at 50.00, so 5.00 a unit; the lorry turns up with 15
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $order = $this->withHeaders($this->manager())->postJson(
            '/api/v1/purchase-orders',
            $this->payload($vendor, $warehouse, $variant),
        )->json('data');
        $inventoryHeaders = $this->inventoryManager();
        $this->forgetAuth();

        // Act — 15 against an order for 10
        $response = $this->withHeaders($inventoryHeaders)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/arrivals",
            ['items' => [['stock_item_id' => $variant->id, 'quantity' => 15]]],
        );

        // Assert — all fifteen are booked in, priced at the line's own landed unit cost, and the
        // order is done
        $response->assertCreated();
        $this->assertDatabaseHas('purchase_orders', ['id' => $order['id'], 'status' => 'completed']);
        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $order['id'], 'stock_item_id' => $variant->id, 'quantity_received' => '15.000',
        ]);
        $this->assertDatabaseHas('stock_arrival_items', [
            'stock_item_id' => $variant->id, 'quantity' => '15.000', 'unit_cost' => '5.000', 'total_cost' => '75.00',
        ]);
        $this->assertDatabaseCount('stock_arrivals', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('15.000', (string) $this->stockOf($warehouse, $variant)?->quantity);
    }

    public function test_an_over_received_line_owes_nothing_and_reports_the_surplus(): void
    {
        // Arrange — 10 ordered, 15 delivered
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $viewerHeaders = $this->viewer();
        $order = $this->withHeaders($this->manager())->postJson(
            '/api/v1/purchase-orders',
            $this->payload($vendor, $warehouse, $variant),
        )->json('data');
        $inventoryHeaders = $this->inventoryManager();
        $this->forgetAuth();
        $this->withHeaders($inventoryHeaders)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/arrivals",
            ['items' => [['stock_item_id' => $variant->id, 'quantity' => 15]]],
        );
        $this->forgetAuth();

        // Act
        $response = $this->withHeaders($viewerHeaders)->getJson("/api/v1/purchase-orders/{$order['id']}");

        // Assert — nothing is still owing, and the five extra are named rather than hidden in a
        // negative remainder
        $response->assertOk()
            ->assertJsonPath('data.items.0.quantity_received', '15.000')
            ->assertJsonPath('data.items.0.quantity_remaining', '0.000')
            ->assertJsonPath('data.items.0.quantity_over_received', '5.000');
    }

    public function test_receiving_an_unordered_variant_is_refused(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $ordered = $this->variant();
        $notOrdered = $this->variant();
        $order = $this->withHeaders($this->manager())->postJson(
            '/api/v1/purchase-orders',
            $this->payload($vendor, $warehouse, $ordered),
        )->json('data');
        $inventoryHeaders = $this->inventoryManager();
        $this->forgetAuth();

        // Act
        $response = $this->withHeaders($inventoryHeaders)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/arrivals",
            ['items' => [['stock_item_id' => $notOrdered->id, 'quantity' => 1]]],
        );

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['items']]);
        $this->assertDatabaseCount('stock_arrivals', 0);
    }

    public function test_receiving_against_a_completed_purchase_order_is_refused(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->inventoryManager();
        $order = $this->withHeaders($this->manager())->postJson(
            '/api/v1/purchase-orders',
            $this->payload($vendor, $warehouse, $variant),
        )->json('data');
        $this->forgetAuth();
        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/arrivals",
            ['items' => [['stock_item_id' => $variant->id, 'quantity' => 10]]],
        );

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/arrivals",
            ['items' => [['stock_item_id' => $variant->id, 'quantity' => 1]]],
        );

        // Assert
        $response->assertStatus(422)->assertJsonPath('status', false);
        $this->assertDatabaseCount('stock_arrivals', 1);
    }

    public function test_receiving_against_a_cancelled_purchase_order_is_refused(): void
    {
        // Arrange
        $order = PurchaseOrder::factory()->cancelled()->create();
        $variant = $this->variant();

        // Act
        $response = $this->withHeaders($this->inventoryManager())->postJson(
            "/api/v1/purchase-orders/{$order->id}/arrivals",
            ['items' => [['stock_item_id' => $variant->id, 'quantity' => 1]]],
        );

        // Assert
        $response->assertStatus(422)->assertJsonPath('status', false);
        $this->assertDatabaseCount('stock_arrivals', 0);
    }

    public function test_received_by_is_stamped_from_the_authenticated_user(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $order = $this->withHeaders($this->manager())->postJson(
            '/api/v1/purchase-orders',
            $this->payload($vendor, $warehouse, $variant),
        )->json('data');

        $user = User::factory()->create();
        $user->givePermissionTo([PermissionName::ViewInventory->value, PermissionName::ManageInventory->value]);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
        $this->forgetAuth();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/arrivals",
            ['items' => [['stock_item_id' => $variant->id, 'quantity' => 10]]],
        );

        // Assert — never accepted from the body, always the caller
        $response->assertCreated()->assertJsonPath('data.received_by', $user->id);
    }

    // ──────────────────────────────── cost & unit tracking ────────────────────────────────

    public function test_creating_a_purchase_order_computes_line_and_order_totals(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variantA = $this->variant();
        $variantB = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/purchase-orders', $this->payload(
            $vendor,
            $warehouse,
            $variantA,
            ['items' => [
                ['stock_item_id' => $variantA->id, 'quantity_ordered' => 10, 'base_total_cost' => 25],
                ['stock_item_id' => $variantB->id, 'quantity_ordered' => 4, 'base_total_cost' => 12],
            ]],
        ));

        // Assert — 25.00 / 10 = 2.500 per unit, 12.00 / 4 = 3.000 per unit, no additional costs
        // so final equals base and the order total is just the two lines summed: 37.00.
        $response->assertCreated()
            ->assertJsonPath('data.items.0.base_total_cost', '25.00')
            ->assertJsonPath('data.items.0.base_unit_cost', '2.500')
            ->assertJsonPath('data.items.0.allocated_additional_cost', '0.00')
            ->assertJsonPath('data.items.0.final_unit_cost', '2.500')
            ->assertJsonPath('data.items.0.final_total_cost', '25.00')
            ->assertJsonPath('data.items.0.unit', 'piece')
            ->assertJsonPath('data.items.1.base_unit_cost', '3.000')
            ->assertJsonPath('data.items.1.final_total_cost', '12.00')
            ->assertJsonPath('data.total_amount', '37.00')
            ->assertJsonPath('data.total_additional_cost', '0.00');

        $this->assertDatabaseHas('purchase_order_items', [
            'stock_item_id' => $variantA->id,
            'base_total_cost' => '25.00', 'base_unit_cost' => '2.500',
            'final_unit_cost' => '2.500', 'final_total_cost' => '25.00',
            'unit' => 'piece',
        ]);
    }

    public function test_updating_a_purchase_order_recomputes_totals_after_syncing_items(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();
        $order = $this->withHeaders($headers)->postJson('/api/v1/purchase-orders', $this->payload(
            $vendor,
            $warehouse,
            $variant,
            ['items' => [['stock_item_id' => $variant->id, 'quantity_ordered' => 10, 'base_total_cost' => 20]]],
        ))->json('data');

        // Act — same line, different total cost
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/purchase-orders/{$order['id']}",
            $this->payload($vendor, $warehouse, $variant, ['items' => [
                ['id' => $order['items'][0]['id'], 'stock_item_id' => $variant->id, 'quantity_ordered' => 10, 'base_total_cost' => 80],
            ]]),
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.total_amount', '80.00');
        $this->assertDatabaseHas('purchase_orders', ['id' => $order['id'], 'total_amount' => '80.00']);
    }

    public function test_base_total_cost_is_required_to_create_a_purchase_order(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();

        // Act — items entry deliberately omits base_total_cost
        $response = $this->withHeaders($headers)->postJson('/api/v1/purchase-orders', $this->payload(
            $vendor,
            $warehouse,
            $variant,
            ['items' => [['stock_item_id' => $variant->id, 'quantity_ordered' => 10]]],
        ));

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['items.0.base_total_cost']]);
    }

    public function test_receiving_a_shipment_carries_cost_onto_the_stock_arrival_item(): void
    {
        // Arrange — order 10 at a base cost of 40.00 (4.00/unit), no additional costs; only 6
        // arrive in this shipment
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $order = $this->withHeaders($this->manager())->postJson('/api/v1/purchase-orders', $this->payload(
            $vendor,
            $warehouse,
            $variant,
            ['items' => [['stock_item_id' => $variant->id, 'quantity_ordered' => 10, 'base_total_cost' => 40]]],
        ))->json('data');
        $headers = $this->inventoryManager();
        $this->forgetAuth();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/arrivals",
            ['items' => [['stock_item_id' => $variant->id, 'quantity' => 6]]],
        );

        // Assert — priced against what actually arrived (6 * 4.00 = 24.00), not the order line's
        // own final_total_cost (10 * 4.00 = 40.00)
        $response->assertCreated()
            ->assertJsonPath('data.items.0.unit_cost', '4.000')
            ->assertJsonPath('data.items.0.total_cost', '24.00');

        $this->assertDatabaseHas('stock_arrival_items', [
            'stock_item_id' => $variant->id, 'unit_cost' => '4.000', 'total_cost' => '24.00',
        ]);
    }

    public function test_receiving_a_shipment_carries_the_landed_cost_including_additional_costs(): void
    {
        // Arrange — order 10 at a base cost of 40.00 (4.00/unit) plus a 10.00 delivery fee, the
        // order's only line, so it absorbs the whole fee: landed cost is (40 + 10) / 10 = 5.00/unit
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $order = $this->withHeaders($this->manager())->postJson('/api/v1/purchase-orders', $this->payload(
            $vendor,
            $warehouse,
            $variant,
            [
                'items' => [['stock_item_id' => $variant->id, 'quantity_ordered' => 10, 'base_total_cost' => 40]],
                'additional_costs' => [['name' => 'Delivery', 'amount' => 10]],
            ],
        ))->json('data');
        $headers = $this->inventoryManager();
        $this->forgetAuth();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order['id']}/arrivals",
            ['items' => [['stock_item_id' => $variant->id, 'quantity' => 6]]],
        );

        // Assert — 6 units at the landed 5.00/unit, not the base 4.00/unit
        $response->assertCreated()
            ->assertJsonPath('data.items.0.unit_cost', '5.000')
            ->assertJsonPath('data.items.0.total_cost', '30.00');

        $this->assertDatabaseHas('stock_arrival_items', [
            'stock_item_id' => $variant->id, 'unit_cost' => '5.000', 'total_cost' => '30.00',
        ]);
    }

    public function test_additional_costs_are_distributed_proportionally_to_each_lines_base_cost(): void
    {
        // Arrange — line A is 75% of the order's base cost, line B is 25%
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variantA = $this->variant();
        $variantB = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/purchase-orders', $this->payload(
            $vendor,
            $warehouse,
            $variantA,
            [
                'items' => [
                    ['stock_item_id' => $variantA->id, 'quantity_ordered' => 4, 'base_total_cost' => 75],
                    ['stock_item_id' => $variantB->id, 'quantity_ordered' => 6, 'base_total_cost' => 25],
                ],
                'additional_costs' => [
                    ['name' => 'Delivery', 'amount' => 10],
                    ['name' => 'Customs', 'amount' => 3],
                ],
            ],
        ));

        // Assert — total additional cost is 13.00; A gets 75% (9.75), B gets 25% (3.25)
        $response->assertCreated()
            ->assertJsonPath('data.total_additional_cost', '13.00')
            ->assertJsonPath('data.total_amount', '113.00')
            ->assertJsonCount(2, 'data.additional_costs')
            ->assertJsonPath('data.items.0.allocated_additional_cost', '9.75')
            ->assertJsonPath('data.items.0.final_total_cost', '84.75')
            ->assertJsonPath('data.items.0.final_unit_cost', '21.188')
            ->assertJsonPath('data.items.1.allocated_additional_cost', '3.25')
            ->assertJsonPath('data.items.1.final_total_cost', '28.25');
    }

    public function test_additional_costs_use_largest_remainder_so_allocated_shares_sum_exactly(): void
    {
        // Arrange — three lines with an identical base cost split a total that doesn't divide
        // evenly by three (1000 cents / 3 = 333.33... each): the leftover cent must land
        // somewhere, and land exactly once, not be dropped or double-counted.
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variantA = $this->variant();
        $variantB = $this->variant();
        $variantC = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/purchase-orders', $this->payload(
            $vendor,
            $warehouse,
            $variantA,
            [
                'items' => [
                    ['stock_item_id' => $variantA->id, 'quantity_ordered' => 10, 'base_total_cost' => 10],
                    ['stock_item_id' => $variantB->id, 'quantity_ordered' => 10, 'base_total_cost' => 10],
                    ['stock_item_id' => $variantC->id, 'quantity_ordered' => 10, 'base_total_cost' => 10],
                ],
                'additional_costs' => [['name' => 'Delivery', 'amount' => 10]],
            ],
        ));

        // Assert — the tied leftover cent goes to the first line (lowest id); the shares still
        // sum to exactly 10.00, not 9.99 or 10.01.
        $response->assertCreated()
            ->assertJsonPath('data.items.0.allocated_additional_cost', '3.34')
            ->assertJsonPath('data.items.1.allocated_additional_cost', '3.33')
            ->assertJsonPath('data.items.2.allocated_additional_cost', '3.33')
            ->assertJsonPath('data.total_additional_cost', '10.00')
            ->assertJsonPath('data.total_amount', '40.00');
    }

    public function test_a_single_line_purchase_order_receives_all_additional_costs(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/purchase-orders', $this->payload(
            $vendor,
            $warehouse,
            $variant,
            [
                'items' => [['stock_item_id' => $variant->id, 'quantity_ordered' => 5, 'base_total_cost' => 50]],
                'additional_costs' => [['name' => 'Unloading', 'amount' => 7.5]],
            ],
        ));

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.items.0.allocated_additional_cost', '7.50')
            ->assertJsonPath('data.items.0.final_total_cost', '57.50')
            ->assertJsonPath('data.items.0.final_unit_cost', '11.500')
            ->assertJsonPath('data.total_amount', '57.50');
    }

    public function test_additional_costs_split_equally_when_every_line_is_free(): void
    {
        // Arrange — every line's base_total_cost is zero, so there is no proportion to split by
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variantA = $this->variant();
        $variantB = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/purchase-orders', $this->payload(
            $vendor,
            $warehouse,
            $variantA,
            [
                'items' => [
                    ['stock_item_id' => $variantA->id, 'quantity_ordered' => 10, 'base_total_cost' => 0],
                    ['stock_item_id' => $variantB->id, 'quantity_ordered' => 5, 'base_total_cost' => 0],
                ],
                'additional_costs' => [['name' => 'Delivery', 'amount' => 5]],
            ],
        ));

        // Assert — split evenly rather than by an undefined proportion
        $response->assertCreated()
            ->assertJsonPath('data.items.0.allocated_additional_cost', '2.50')
            ->assertJsonPath('data.items.0.final_total_cost', '2.50')
            ->assertJsonPath('data.items.1.allocated_additional_cost', '2.50')
            ->assertJsonPath('data.items.1.final_total_cost', '2.50')
            ->assertJsonPath('data.total_amount', '5.00');
    }

    public function test_updating_additional_costs_by_id_recomputes_allocation(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();
        $order = $this->withHeaders($headers)->postJson('/api/v1/purchase-orders', $this->payload(
            $vendor,
            $warehouse,
            $variant,
            [
                'items' => [['stock_item_id' => $variant->id, 'quantity_ordered' => 10, 'base_total_cost' => 100]],
                'additional_costs' => [['name' => 'Delivery', 'amount' => 20]],
            ],
        ))->json('data');
        $costId = $order['additional_costs'][0]['id'];

        // Act — same cost, higher amount
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/purchase-orders/{$order['id']}",
            $this->payload($vendor, $warehouse, $variant, [
                'items' => [
                    ['id' => $order['items'][0]['id'], 'stock_item_id' => $variant->id, 'quantity_ordered' => 10, 'base_total_cost' => 100],
                ],
                'additional_costs' => [['id' => $costId, 'name' => 'Delivery', 'amount' => 50]],
            ]),
        );

        // Assert — updated in place, not duplicated
        $response->assertOk()
            ->assertJsonCount(1, 'data.additional_costs')
            ->assertJsonPath('data.total_additional_cost', '50.00')
            ->assertJsonPath('data.items.0.final_total_cost', '150.00');
        $this->assertDatabaseCount('purchase_order_additional_costs', 1);
        $this->assertDatabaseHas('purchase_order_additional_costs', ['id' => $costId, 'amount' => '50.00']);
    }

    public function test_removing_all_additional_costs_resets_allocation_to_base(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();
        $order = $this->withHeaders($headers)->postJson('/api/v1/purchase-orders', $this->payload(
            $vendor,
            $warehouse,
            $variant,
            [
                'items' => [['stock_item_id' => $variant->id, 'quantity_ordered' => 10, 'base_total_cost' => 100]],
                'additional_costs' => [['name' => 'Delivery', 'amount' => 20]],
            ],
        ))->json('data');
        $costId = $order['additional_costs'][0]['id'];

        // Act — additional_costs omitted entirely: the whole current set, which is now empty
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/purchase-orders/{$order['id']}",
            $this->payload($vendor, $warehouse, $variant, [
                'items' => [
                    ['id' => $order['items'][0]['id'], 'stock_item_id' => $variant->id, 'quantity_ordered' => 10, 'base_total_cost' => 100],
                ],
            ]),
        );

        // Assert
        $response->assertOk()
            ->assertJsonCount(0, 'data.additional_costs')
            ->assertJsonPath('data.total_additional_cost', '0.00')
            ->assertJsonPath('data.items.0.allocated_additional_cost', '0.00')
            ->assertJsonPath('data.items.0.final_total_cost', '100.00');
        $this->assertSoftDeleted('purchase_order_additional_costs', ['id' => $costId]);
    }

    public function test_an_additional_cost_name_is_required_when_additional_costs_are_present(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/purchase-orders', $this->payload(
            $vendor,
            $warehouse,
            $variant,
            ['additional_costs' => [['amount' => 10]]],
        ));

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['additional_costs.0.name']]);
    }

    public function test_an_additional_costs_amount_cannot_be_negative(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/purchase-orders', $this->payload(
            $vendor,
            $warehouse,
            $variant,
            ['additional_costs' => [['name' => 'Delivery', 'amount' => -5]]],
        ));

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['additional_costs.0.amount']]);
    }

    public function test_updating_with_an_additional_cost_id_from_another_order_is_refused(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->variant();
        $headers = $this->manager();
        $ownOrder = $this->withHeaders($headers)->postJson(
            '/api/v1/purchase-orders',
            $this->payload($vendor, $warehouse, $variant),
        )->json('data');
        $otherOrder = $this->withHeaders($headers)->postJson('/api/v1/purchase-orders', $this->payload(
            $vendor,
            $warehouse,
            $variant,
            ['additional_costs' => [['name' => 'Delivery', 'amount' => 10]]],
        ))->json('data');
        $foreignCostId = $otherOrder['additional_costs'][0]['id'];

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/purchase-orders/{$ownOrder['id']}",
            $this->payload($vendor, $warehouse, $variant, [
                'items' => [
                    ['id' => $ownOrder['items'][0]['id'], 'stock_item_id' => $variant->id, 'quantity_ordered' => 10, 'base_total_cost' => 50],
                ],
                'additional_costs' => [['id' => $foreignCostId, 'name' => 'Delivery', 'amount' => 10]],
            ]),
        );

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['additional_costs']]);
    }

    public function test_a_plain_stock_arrival_never_carries_cost(): void
    {
        // Arrange
        $warehouse = Warehouse::factory()->create();
        $vendor = Vendor::factory()->create();
        $variant = $this->variant();

        // Act — the generic endpoint, not raised against a purchase order
        $response = $this->withHeaders($this->inventoryManager())->postJson('/api/v1/stock-arrivals', [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'items' => [['stock_item_id' => $variant->id, 'quantity' => 10]],
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.items.0.unit_cost', null)
            ->assertJsonPath('data.items.0.total_cost', null);
    }
}
