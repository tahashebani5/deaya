<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\City;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\RecalculateOrderTotals;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The archive: deleted orders, read through the same screens the live list uses.
 *
 * «إلغاء تام» says an order happened and then ended; a delete says it should never have been
 * recorded — a duplicate, a wrong number, somebody's trial. The two are different questions, so
 * the archive is a different list, behind its own grant, and the live list never shows a row
 * that is in it.
 *
 * Three things this file is really guarding, because each of them fails *silently*:
 *
 * - The two chip rows agreeing. The status counts and the payment counts are separate queries
 *   that seed their own `Order::query()`, so an archive flag that reached one and not the other
 *   would put an archived status row above a live payment row on one screen.
 * - `logs.view` not becoming a back door. Reading an order's history is a different decision
 *   from reading the archive, and the history endpoint is the one that would quietly grant both.
 * - `available_transitions` staying off an archived row. The shared card offers a status move on
 *   any row carrying it, and an archived order must not be movable.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderArchiveTest extends TestCase
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
    private function auth(PermissionName ...$permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (PermissionName $p) => $p->value, $permissions));

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** Somebody who may read orders and knows nothing of the archive. */
    private function viewer(): array
    {
        return $this->auth(PermissionName::ViewOrders);
    }

    /** Somebody who may read orders and open the archive. */
    private function archivist(): array
    {
        return $this->auth(PermissionName::ViewOrders, PermissionName::ViewOrderArchive);
    }

    /**
     * An order whose stock has actually left the warehouse, with the ledger rows to prove it.
     *
     * Built by walking the real status path rather than by writing
     * `order_items.fulfillment_stock_movement_id` by hand: the preview under test is derived
     * from the movement ledger, and a fixture that faked the ledger would assert nothing about
     * whether the derivation is right.
     *
     * @return array{0: Order, 1: array<string, string>}
     */
    private function fulfilledOrder(): array
    {
        $product = Product::factory()->create(['name' => 'كيس شحن']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'label' => '25*35']);
        $warehouse = Warehouse::factory()->create();

        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ViewOrderArchive,
            PermissionName::MoveOrderToReadyToPrint,
            PermissionName::MoveOrderToPrinting,
            PermissionName::MoveOrderToReady,
            PermissionName::CancelOrders,
            PermissionName::DeleteOrders,
            PermissionName::ViewInventory,
            PermissionName::ManageInventory,
        );

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $variant->stock_item_id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 1000,
            'unit_cost' => 5,
        ])->assertCreated();

        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => '300',
        ]);

        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::ReadyToPrint->value,
            'fields' => ['warehouse_id' => $warehouse->id],
        ])->assertOk();

        return [$order->refresh(), $headers];
    }

    public function test_the_archive_lists_the_deleted_orders_and_only_those(): void
    {
        // Arrange
        $archived = Order::factory()->create();
        $archived->delete();
        Order::factory()->count(2)->create();
        $headers = $this->archivist();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/archive');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $archived->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_live_list_never_shows_a_deleted_order(): void
    {
        // Arrange
        $archived = Order::factory()->create();
        $archived->delete();
        $live = Order::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $live->id);
    }

    public function test_the_word_archive_is_not_read_as_an_order_id(): void
    {
        // Arrange — a live order sits where implicit binding would look
        Order::factory()->create();
        $headers = $this->archivist();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/archive');

        // Assert — the route is declared before the resource for exactly this reason
        $response->assertOk()->assertJsonStructure(['data', 'meta' => ['total']]);
    }

    public function test_the_archive_is_not_opened_by_the_permission_to_read_orders(): void
    {
        // Arrange
        $archived = Order::factory()->create();
        $archived->delete();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/archive');

        // Assert
        $response->assertForbidden();
    }

    public function test_the_archive_summary_counts_the_deleted_orders_and_only_those(): void
    {
        // Arrange
        $printing = Order::factory()->status(OrderStatus::Printing)->create();
        $printing->delete();
        $cancelled = Order::factory()->status(OrderStatus::Cancelled)->create();
        $cancelled->delete();
        Order::factory()->count(4)->status(OrderStatus::Printing)->create();
        $headers = $this->archivist();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/archive/summary');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.counts.printing', 1)
            ->assertJsonPath('data.counts.cancelled', 1)
            ->assertJsonPath('data.total', 2);
    }

    public function test_the_payment_counts_follow_the_archive_too(): void
    {
        // Arrange — one archived order against four live ones, every one of them unpaid
        $archived = Order::factory()->status(OrderStatus::Printing)->create();
        $archived->delete();
        Order::factory()->count(4)->status(OrderStatus::Printing)->create();
        $headers = $this->archivist();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/archive/summary');

        // Assert — the trap this test exists for: `withoutPaymentStatuses()` is a hand-written
        // `new self(...)`, so a flag missing from it leaves the payment chips counting the live
        // list while the status chips beside them count the archive.
        $response->assertOk()
            ->assertJsonPath('data.counts.printing', 1)
            ->assertJsonPath('data.payment_counts.unpaid', 1);
    }

    public function test_the_archive_summary_needs_the_archive_permission(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/archive/summary');

        // Assert
        $response->assertForbidden();
    }

    public function test_the_archive_takes_the_same_filters_the_list_takes(): void
    {
        // Arrange
        $printing = Order::factory()->status(OrderStatus::Printing)->create();
        $printing->delete();
        $ready = Order::factory()->status(OrderStatus::Ready)->create();
        $ready->delete();
        $headers = $this->archivist();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/archive?status=ready');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ready->id);
    }

    public function test_the_archive_is_searched_by_order_number_like_the_live_list(): void
    {
        // Arrange — two archived orders, one of them the one being looked for. Searching the
        // archive is not a nicety: it is the whole reason somebody opens it, because the thing
        // they are hunting is an order that has vanished from every other screen.
        $wanted = Order::factory()->create();
        $wanted->delete();
        $other = Order::factory()->create();
        $other->delete();
        $headers = $this->archivist();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/archive?search='.$wanted->code);

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wanted->id);
    }

    public function test_the_archive_is_narrowed_by_customer_and_by_city(): void
    {
        // Arrange — the two «who and where» axes, asserted together because they are applied by
        // the same `when()` pair and would fail together if the archive flag short-circuited them
        $customer = Customer::factory()->create();
        $city = City::factory()->create();

        $wanted = Order::factory()->forCustomer($customer)->create(['city_id' => $city->id]);
        $wanted->delete();
        $wrongCustomer = Order::factory()->create(['city_id' => $city->id]);
        $wrongCustomer->delete();
        $wrongCity = Order::factory()->forCustomer($customer)->create();
        $wrongCity->delete();
        $headers = $this->archivist();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson("/api/v1/orders/archive?customer_id={$customer->id}&city_id={$city->id}");

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wanted->id);
    }

    public function test_the_archive_is_narrowed_by_urgency_and_by_payment_state(): void
    {
        // Arrange — «مستعجلة» crosses the status axis rather than narrowing it, and the payment
        // state is the axis `withoutPaymentStatuses()` drops for the counts. Both are read on the
        // list itself here, where no such copy is made.
        $urgent = Order::factory()->create(['is_urgent' => true]);
        $urgent->delete();
        $calm = Order::factory()->create(['is_urgent' => false]);
        $calm->delete();
        $headers = $this->archivist();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/archive?urgent=1&payment_status=unpaid');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $urgent->id);
    }

    public function test_the_archive_is_bounded_by_a_date_range(): void
    {
        // Arrange — `placed_at`, turned into a pair of UTC instants in the shop's own timezone.
        // An order taken at one in the morning is 23:00 *yesterday* in UTC, so a `whereDate`
        // reading would drop it for the first two hours of every day — archived or not.
        $inside = Order::factory()->create(['placed_at' => now()->subDays(3)]);
        $inside->delete();
        $tooOld = Order::factory()->create(['placed_at' => now()->subDays(30)]);
        $tooOld->delete();
        $headers = $this->archivist();

        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/archive?from={$from}&to={$to}");

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inside->id);
    }

    public function test_the_archive_is_sorted_oldest_first_when_asked(): void
    {
        // Arrange — «الأقدم أولاً» flips the direction of both keys `OrderListQuery` orders on,
        // `placed_at` then `id`. It is asserted here because §٨ turns on it: the app deliberately
        // does *not* `insert()` a restored order into a list whose direction it cannot know.
        $older = Order::factory()->create(['placed_at' => now()->subDays(5)]);
        $older->delete();
        $newer = Order::factory()->create(['placed_at' => now()->subDay()]);
        $newer->delete();
        $headers = $this->archivist();

        // Act
        $newestFirst = $this->withHeaders($headers)->getJson('/api/v1/orders/archive?sort=newest');
        $oldestFirst = $this->withHeaders($headers)->getJson('/api/v1/orders/archive?sort=oldest');

        // Assert
        $newestFirst->assertOk()->assertJsonPath('data.0.id', $newer->id);
        $oldestFirst->assertOk()->assertJsonPath('data.0.id', $older->id);
    }

    public function test_the_archive_counts_are_narrowed_by_the_same_filters_the_list_takes(): void
    {
        // Arrange — one archived urgent order and one archived calm one, so a counts endpoint
        // that dropped the filter would answer 2 where the list beneath it shows 1
        $urgent = Order::factory()->status(OrderStatus::Printing)->create(['is_urgent' => true]);
        $urgent->delete();
        $calm = Order::factory()->status(OrderStatus::Printing)->create(['is_urgent' => false]);
        $calm->delete();
        $headers = $this->archivist();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/archive/summary?urgent=1');

        // Assert — both chip rows narrow together, which is the whole point of the flag living on
        // the filter rather than in one of the three queries
        $response->assertOk()
            ->assertJsonPath('data.counts.printing', 1)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.payment_counts.unpaid', 1);
    }

    public function test_the_live_summary_is_untouched_by_the_archive(): void
    {
        // Arrange — the mirror of every test above, and the one that would catch an
        // `onlyTrashed()` that leaked out of the archive route into the shared query
        $archived = Order::factory()->status(OrderStatus::Printing)->create();
        $archived->delete();
        Order::factory()->count(3)->status(OrderStatus::Printing)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/summary');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.counts.printing', 3)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.payment_counts.unpaid', 3);
    }

    public function test_a_filter_naming_nothing_is_dropped_rather_than_refused(): void
    {
        // Arrange
        $archived = Order::factory()->status(OrderStatus::Ready)->create();
        $archived->delete();
        $headers = $this->archivist();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/archive?status=ملغاة');

        // Assert — OrderFilters drops a value that names no status, and the archive must behave
        // exactly as the live list does or the two screens disagree about the same query string.
        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_archived_row_says_when_it_was_deleted_and_offers_no_status_moves(): void
    {
        // Arrange
        $archived = Order::factory()->status(OrderStatus::Printing)->create();
        $archived->delete();
        $headers = $this->archivist();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/archive');

        // Assert — the key is *absent*, not empty: the shared card offers a move on any row
        // carrying `available_transitions`, and `progress()` is a query per row for exactly the
        // statuses the archive is full of.
        $response->assertOk()
            ->assertJsonPath('data.0.deleted_at', fn ($value) => is_string($value))
            ->assertJsonMissingPath('data.0.available_transitions')
            ->assertJsonMissingPath('data.0.progress');
    }

    public function test_a_live_row_keeps_its_moves_and_carries_a_null_deleted_at(): void
    {
        // Arrange
        Order::factory()->status(OrderStatus::Printing)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.deleted_at', null)
            ->assertJsonStructure(['data' => [['available_transitions', 'progress']]]);
    }

    public function test_an_archived_order_can_be_read_by_somebody_holding_the_archive(): void
    {
        // Arrange
        $archived = Order::factory()->create();
        $archived->delete();
        $headers = $this->archivist();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$archived->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.id', $archived->id)
            ->assertJsonPath('data.deleted_at', fn ($value) => is_string($value));
    }

    public function test_reading_an_archived_order_needs_more_than_the_permission_to_read_orders(): void
    {
        // Arrange
        $archived = Order::factory()->create();
        $archived->delete();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$archived->id}");

        // Assert
        $response->assertForbidden();
    }

    public function test_the_archive_refusal_names_the_grant_the_reader_should_ask_for(): void
    {
        // Arrange — somebody who holds every grant the endpoint itself asks for, and is refused
        // by a fact about this one order
        $archived = Order::factory()->create();
        $archived->delete();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$archived->id}");

        // Assert — not the blanket «ليس لديك صلاحية لتنفيذ هذا الإجراء». The reader passed the
        // route's own `can:`, so the generic sentence would send them to ask for a permission
        // they already have; this one names the door they are actually standing at.
        $response->assertForbidden()
            ->assertJsonPath('message', 'هذه الطلبية في الأرشيف، وعرضها يحتاج صلاحية عرض أرشيف الطلبات');
    }

    public function test_a_live_order_is_read_without_the_archive_grant(): void
    {
        // Arrange — the other half of the middleware, and the one that would fail silently if it
        // charged the archive's grant on every row rather than only on a deleted one
        $live = Order::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$live->id}");

        // Assert
        $response->assertOk()->assertJsonPath('data.deleted_at', null);
    }

    public function test_the_change_log_is_not_a_back_door_into_the_archive(): void
    {
        // Arrange
        $archived = Order::factory()->create();
        $archived->delete();
        $headers = $this->auth(PermissionName::ViewOrders, PermissionName::ViewActivityLogs);

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$archived->id}/logs");

        // Assert
        $response->assertForbidden();
    }

    public function test_the_change_log_of_an_archived_order_opens_for_an_archive_reader(): void
    {
        // Arrange
        $archived = Order::factory()->create();
        $archived->delete();
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ViewActivityLogs,
            PermissionName::ViewOrderArchive,
        );

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$archived->id}/logs");

        // Assert
        $response->assertOk();
    }

    public function test_the_money_on_an_archived_order_needs_the_archive_permission(): void
    {
        // Arrange
        $archived = Order::factory()->create();
        $archived->delete();
        $headers = $this->auth(PermissionName::ViewOrders, PermissionName::ViewOrderPayments);

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$archived->id}/payments");

        // Assert
        $response->assertForbidden();
    }

    public function test_the_money_on_an_archived_order_opens_for_an_archive_reader(): void
    {
        // Arrange
        $archived = Order::factory()->create();
        $archived->delete();
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ViewOrderPayments,
            PermissionName::ViewOrderArchive,
        );

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$archived->id}/payments");

        // Assert
        $response->assertOk();
    }

    public function test_an_archived_order_is_still_out_of_reach_of_the_write_routes(): void
    {
        // Arrange
        $archived = Order::factory()->status(OrderStatus::New)->create();
        $archived->delete();
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ViewOrderArchive,
            PermissionName::MoveOrderToDesigning,
        );

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$archived->id}/status", [
            'status' => OrderStatus::Designing->value,
        ]);

        // Assert — sixteen of the eighteen {order} routes stay 404 on an archived order, and
        // reading is the whole of the exception.
        $response->assertNotFound();
    }

    public function test_the_show_response_previews_what_a_delete_would_put_back(): void
    {
        // Arrange
        [$order, $headers] = $this->fulfilledOrder();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.stock_effect.stock.kind', 'return')
            ->assertJsonPath('data.stock_effect.stock.warning', 'سيُعاد إلى المخزن ما خصمته هذه الطلبية:')
            ->assertJsonPath('data.stock_effect.stock.lines.0.label', fn ($label) => str_ends_with((string) $label, ' — 25*35'))
            ->assertJsonPath('data.stock_effect.stock.lines.0.quantity', '300')
            ->assertJsonPath('data.stock_effect.stock.lines.0.unit', 'قطعة')
            ->assertJsonPath('data.stock_effect.stock.note', null)
            // No money on this order, so §٧٫١'s section is absent rather than empty.
            ->assertJsonPath('data.stock_effect.money', null);
    }

    public function test_an_order_that_drew_no_stock_says_plainly_that_nothing_moves(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::New)->create();
        OrderItem::factory()->for($order)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.stock_effect.stock.kind', 'none')
            ->assertJsonPath('data.stock_effect.stock.lines', [])
            ->assertJsonPath('data.stock_effect.stock.note', null);
    }

    public function test_the_preview_reads_the_ledger_rather_than_stock_deducted_at(): void
    {
        // Arrange — drawn, then cancelled, which credits the goods back and leaves
        // `orders.stock_deducted_at` standing. An order in this state has nothing to return.
        [$order, $headers] = $this->fulfilledOrder();
        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Cancelled->value,
            'reason' => 'العميل تراجع',
        ])->assertOk();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert — reading the column instead of the ledger would promise the warehouse goods
        // the cancellation already put back, and the second return would break the partial
        // unique index on `stock_movements.reverses_movement_id` with a raw 500.
        $response->assertOk()
            ->assertJsonPath('data.stock_deducted_at', fn ($value) => is_string($value))
            ->assertJsonPath('data.stock_effect.stock.kind', 'none')
            ->assertJsonPath('data.stock_effect.stock.lines', []);
    }

    public function test_an_archived_order_previews_what_a_restore_would_deduct_again(): void
    {
        // Arrange — drawn, then deleted through the endpoint itself rather than by calling
        // `delete()` on the model: what a restore would re-draw is a fact about what *this
        // delete* returned, and only the real path records it.
        [$order, $headers] = $this->fulfilledOrder();
        $this->withHeaders($headers)->deleteJson("/api/v1/orders/{$order->id}")->assertOk();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert — and the second paragraph is not decoration: the new draw eats today's layers,
        // so the order comes back at a cost it did not leave with, and that is said out loud.
        $response->assertOk()
            ->assertJsonPath('data.stock_effect.stock.kind', 'rededuct')
            ->assertJsonPath('data.stock_effect.stock.warning', 'سيُخصم من المخزن من جديد:')
            ->assertJsonPath('data.stock_effect.stock.lines.0.label', fn ($label) => str_ends_with((string) $label, ' — 25*35'))
            ->assertJsonPath(
                'data.stock_effect.stock.note',
                'وقد تختلف تكلفة الطلبية عمّا كانت، لأن الخصم الجديد يأكل طبقات اليوم',
            );
    }

    public function test_no_list_row_ever_carries_a_stock_effect(): void
    {
        // Arrange
        [$order] = $this->fulfilledOrder();
        $headers = $this->archivist();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders');

        // Assert — it is built from the lines and their movements, and a page of twenty would be
        // a ledger read per row for a warning no card shows.
        $response->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonMissingPath('data.0.stock_effect');
    }

    public function test_deleting_an_order_puts_it_in_the_archive(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::New)->create();
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ViewOrderArchive,
            PermissionName::DeleteOrders,
        );

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/orders/{$order->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.deleted_at', fn ($value) => is_string($value));
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_deleting_needs_its_own_permission(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/orders/{$order->id}");

        // Assert
        $response->assertForbidden();
        $this->assertNotSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_restoring_reaches_an_order_that_is_already_archived(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::New)->create();
        $order->delete();
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ViewOrderArchive,
            PermissionName::RestoreOrders,
        );

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/restore");

        // Assert — without `->withTrashed()` on the route, implicit binding refuses to resolve
        // the one order this endpoint exists for, and the answer is 404 for ever.
        $response->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.deleted_at', null);
        $this->assertNotSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_restoring_needs_its_own_permission(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $order->delete();
        $headers = $this->auth(PermissionName::ViewOrders, PermissionName::ViewOrderArchive);

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/restore");

        // Assert — the grant is its own: restoring puts stock back out of the warehouse, which
        // is not what somebody who may merely read the archive agreed to.
        $response->assertForbidden();
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_reinstating_designing_and_the_carrier_all_stay_out_of_the_archive(): void
    {
        // Arrange — the rest of the sixteen. `test_an_archived_order_is_still_out_of_reach_of_the
        // _write_routes` pins the status endpoint; this walks the other shapes, because each is a
        // separately declared route and `->withTrashed()` is added one route at a time.
        $archived = Order::factory()->status(OrderStatus::Cancelled)->create();
        $archived->delete();
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ViewOrderArchive,
            PermissionName::CancelOrders,
            PermissionName::ManageOrderDesigns,
            PermissionName::ManageCarrierParcels,
            PermissionName::RecordOrderPayments,
        );

        // Act
        $reinstate = $this->withHeaders($headers)->postJson("/api/v1/orders/{$archived->id}/reinstate");
        $design = $this->withHeaders($headers)->postJson("/api/v1/orders/{$archived->id}/designs", []);
        $lodge = $this->withHeaders($headers)->postJson("/api/v1/carrier/orders/{$archived->id}/lodge");
        $pay = $this->withHeaders($headers)->postJson("/api/v1/orders/{$archived->id}/payments", []);

        // Assert — 404 from the binding itself, before any handler runs. An archived order is
        // not a thing that can be worked on; it is a thing that can be read and restored.
        $reinstate->assertNotFound();
        $design->assertNotFound();
        $lodge->assertNotFound();
        $pay->assertNotFound();
    }

    public function test_deleting_twice_answers_with_a_sentence_rather_than_a_crash(): void
    {
        // Arrange — two people pressing the same button, or one person pressing it twice on a
        // slow connection
        $order = Order::factory()->status(OrderStatus::New)->create();
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ViewOrderArchive,
            PermissionName::DeleteOrders,
        );

        $this->withHeaders($headers)->deleteJson("/api/v1/orders/{$order->id}")->assertOk();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/orders/{$order->id}");

        // Assert — the route carries no `->withTrashed()`, so the second attempt never reaches
        // the action at all: implicit binding cannot resolve an archived order and answers 404,
        // which is a clean 4xx and the honest one — «لم أجد طلبيةً حيّةً بهذا الرقم». The
        // domain's own «محذوفة أصلاً» stands behind it for any caller holding a live model.
        $response->assertNotFound();
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_restoring_an_order_that_is_not_in_the_archive_is_a_422_that_says_so(): void
    {
        // Arrange — the mirror case, and the one that *does* reach the action, because the
        // restore route resolves trashed and live orders alike
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ViewOrderArchive,
            PermissionName::RestoreOrders,
        );

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/restore");

        // Assert — every one of this feature's refusals is a `DomainException`, which
        // bootstrap/app.php already renders as a 422 carrying the Arabic sentence, so no new
        // rendering was needed and none may be lost
        $response->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'الطلبية ليست محذوفة'));
        $this->assertNotSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_deleting_an_order_carrying_money_reverses_it_instead_of_refusing(): void
    {
        // Arrange — §٢٫١, decided 2026-09-10: this used to be the 422 a person was most likely
        // to meet («اعكس الدفعات أولاً»), and the user chose the opposite. Read here as the API
        // renders it, because the domain's own assertions live in `OrderDeletionMoneyTest`.
        $order = Order::factory()->status(OrderStatus::Delivered)->create();
        OrderItem::factory()->for($order)->create();
        app(RecalculateOrderTotals::class)($order->refresh());

        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ViewOrderArchive,
            PermissionName::DeleteOrders,
            PermissionName::RecordOrderPayments,
        );

        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/payments", [
            'amount' => '150',
            'method' => PaymentMethod::Cash->value,
        ])->assertCreated();

        $this->assertSame('150.00', (string) $order->refresh()->paid_amount);

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/orders/{$order->id}");

        // Assert — archived, and the ledger balanced by a second row rather than an edited one
        $response->assertOk();
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
        $this->assertSame('0.00', (string) $order->refresh()->paid_amount);
        $this->assertSame(2, OrderPayment::query()->where('order_id', $order->id)->count());
    }

    public function test_the_delete_confirmation_puts_the_money_ahead_of_the_stock(): void
    {
        // Arrange — §٧٫١: one button can undo a cash collection, which is heavier than anything
        // it does to a shelf, so the section naming it is first and the sentence saying the
        // restore will not bring it back is in it.
        [$order, $headers] = $this->fulfilledOrder();

        $paid = $order->refresh()->remainingAmount();

        $payer = $this->auth(PermissionName::ViewOrders, PermissionName::RecordOrderPayments);
        $this->withHeaders($payer)->postJson("/api/v1/orders/{$order->id}/payments", [
            'amount' => $paid,
            'method' => PaymentMethod::Cash->value,
        ])->assertCreated();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert — including the key order itself, which §٧٫١ made the server's decision
        $response->assertOk()
            ->assertJsonPath('data.stock_effect', fn (array $effect) => array_keys($effect) === ['money', 'stock'])
            ->assertJsonPath('data.stock_effect.money.warning', 'سيُعكس ما قُبض على هذه الطلبية:')
            ->assertJsonPath('data.stock_effect.money.lines.0.label', 'مدفوع')
            ->assertJsonPath('data.stock_effect.money.lines.0.amount', $paid)
            ->assertJsonPath('data.stock_effect.money.lines.0.currency', 'د.ل')
            ->assertJsonPath(
                'data.stock_effect.money.note',
                fn ($note) => str_contains((string) $note, 'الاستعادة لا تُعيدها'),
            )
            ->assertJsonPath('data.stock_effect.stock.kind', 'return');
    }

    public function test_the_archive_and_the_restore_are_both_written_into_the_order_history(): void
    {
        // Arrange — through the API rather than the action, because the fact under test is that
        // the *employee* is recorded: Spatie resolves the causer from the authenticated user, and
        // a domain call made with no guard would write a history entry attributed to nobody.
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewOrders->value,
            PermissionName::ViewOrderArchive->value,
            PermissionName::DeleteOrders->value,
            PermissionName::RestoreOrders->value,
            PermissionName::ViewActivityLogs->value,
        ]);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
        $order = Order::factory()->status(OrderStatus::New)->create();

        // Act
        $this->withHeaders($headers)->deleteJson("/api/v1/orders/{$order->id}")->assertOk();
        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/restore")->assertOk();

        // Assert — «تم الحذف» and «تم الاسترجاع», in that order, each against the person who did
        // it. This is the whole answer to «من حذف الطلبية؟», and §٩٫٣ says the archive shows up
        // in the ordinary change log rather than hiding from it.
        $entries = ActivityLog::query()
            ->where('subject_type', $order->getMorphClass())
            ->where('subject_id', $order->getKey())
            ->whereIn('event', [AuditEvent::Deleted->value, AuditEvent::Restored->value])
            ->orderBy('id')
            ->get();

        $this->assertSame(
            [AuditEvent::Deleted->value, AuditEvent::Restored->value],
            $entries->pluck('event')->all(),
        );
        $this->assertSame(
            [(int) $user->getKey(), (int) $user->getKey()],
            $entries->pluck('causer_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_the_archived_order_history_reads_back_through_its_own_endpoint(): void
    {
        // Arrange — the two facts joined: the delete is logged, and the log of an archived order
        // is reachable by somebody holding the archive. Either alone leaves «من حذفها؟»
        // unanswerable on the only screen that can still show the order.
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewOrders->value,
            PermissionName::ViewOrderArchive->value,
            PermissionName::DeleteOrders->value,
            PermissionName::ViewActivityLogs->value,
        ]);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
        $order = Order::factory()->status(OrderStatus::New)->create();

        $this->withHeaders($headers)->deleteJson("/api/v1/orders/{$order->id}")->assertOk();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}/logs");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.event', AuditEvent::Deleted->value);
    }
}
