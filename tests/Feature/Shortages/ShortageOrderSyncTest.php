<?php

declare(strict_types=1);

namespace Tests\Feature\Shortages;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\RecalculateOrderTotals;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Enums\ShortageRevision;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\OrderService;
use App\Domain\Shortage\Actions\CloseShortagesForOrder;
use App\Domain\Shortage\Actions\SyncShortagesFromOrder;
use App\Domain\Shortage\DTOs\ShortageSupplyData;
use App\Domain\Shortage\Enums\ShortageSource;
use App\Domain\Shortage\Enums\ShortageStatus;
use App\Domain\Shortage\Enums\SupplyKind;
use App\Domain\Shortage\Models\Shortage;
use App\Domain\Shortage\Models\ShortageSupply;
use App\Domain\Shortage\ShortageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The reconciliation between an order's lines and the shortages section.
 *
 * **These are the tests the feature is actually risky without.** `order_items.shortage_quantity`
 * is subtracted from what the customer is billed, so a shortage mirrored twice is an invoice cut
 * twice — and the formula that prevents it (`required = shortage_quantity + Σ supplied`) is not
 * self-evident from reading either side on its own.
 *
 * The sync runs from a queued, after-commit listener. These tests drive it directly rather than
 * asserting through a queue: what is under test is the arithmetic, and a test that also proved
 * Laravel dispatches events would fail for two reasons and name neither.
 *
 * Arrange - Act - Assert throughout.
 */
class ShortageOrderSyncTest extends TestCase
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
     * One line of 300 at 1.550, on an order sitting in «نواقص» so its lines are open.
     *
     * @return array{0: Order, 1: OrderItem}
     */
    private function orderOf300(OrderStatus $status = OrderStatus::Shortage): array
    {
        $order = Order::factory()->status($status)->create(['delivery_price' => '20.00']);

        $item = OrderItem::factory()->for($order)->create([
            'variant_label' => '25*35',
            'quantity' => '300.000',
            'unit_price' => '1.550',
            'line_total' => '465.00',
        ]);

        app(RecalculateOrderTotals::class)($order->refresh());

        return [$order->refresh(), $item];
    }

    /**
     * Writes what is missing through the one writer, naming why — exactly as each of its three
     * callers does. The reason is not decoration: it is what tells «وصلت البضاعة» from «أخطأت»
     * when both leave the line at zero. See {@see ShortageRevision}.
     */
    private function declareShortage(
        Order $order,
        OrderItem $item,
        ?string $missing,
        ShortageRevision $reason = ShortageRevision::Declared,
    ): void {
        app(OrderService::class)->setShortages(
            $order->refresh(),
            [$item->getKey() => $missing],
            null,
            $reason,
        );
    }

    private function sync(Order $order, ShortageRevision $reason = ShortageRevision::Declared): void
    {
        app(SyncShortagesFromOrder::class)((int) $order->getKey(), $reason);
    }

    /**
     * Records a purchase against an order-born shortage, the way the endpoint does.
     *
     * **A warehouse and a signer, because a supply is the goods arriving.** An order-born
     * shortage names a size, so its purchase posts a `PurchaseArrival` onto a shelf — without
     * somewhere to put them the domain refuses with `SupplyNeedsAWarehouse`, which is the whole
     * point of the arrangement and not something a test should route around. See
     * `ShortageStockArrivalTest` for the mechanics themselves.
     */
    private function buy(
        Shortage $shortage,
        string $quantity,
        string $amount,
        PaymentMethod $method = PaymentMethod::Cash,
    ): ShortageSupply {
        return app(ShortageService::class)->recordSupply(
            $shortage,
            new ShortageSupplyData(
                quantity: $quantity,
                amount: $amount,
                method: $method,
                occurredOn: now()->toDateString(),
                warehouseId: (int) $this->warehouse()->getKey(),
            ),
            $this->buyer(),
        );
    }

    /** One shelf for the whole test — a second would split the balances for no reason. */
    private function warehouse(): Warehouse
    {
        return $this->warehouse ??= Warehouse::factory()->create();
    }

    /** A stock movement is signed, always — see `SupplyRequiresAnActor`. */
    private function buyer(): User
    {
        return $this->buyer ??= User::factory()->create();
    }

    private ?Warehouse $warehouse = null;

    private ?User $buyer = null;

    // ── creation and the duplicate guard ────────────────────────────────────────────────

    public function test_a_short_line_becomes_a_shortage_carrying_the_order_and_the_customer(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $this->declareShortage($order, $item, '30');

        // Act
        $this->sync($order);

        // Assert
        $shortage = Shortage::query()->where('order_item_id', $item->getKey())->firstOrFail();

        $this->assertSame(ShortageSource::FromOrder, $shortage->source);
        $this->assertSame((int) $order->getKey(), (int) $shortage->order_id);
        $this->assertSame((int) $order->customer_id, (int) $shortage->customer_id);
        $this->assertSame('30.000', (string) $shortage->required_quantity);
        $this->assertSame(ShortageStatus::New, $shortage->status);
        // Nobody chose to chase it yet — an order landing in «نواقص» at 2am belongs to no one.
        $this->assertNull($shortage->assigned_to_user_id);
    }

    /**
     * Only the lines that are actually short — «أكياس شحن ناقص ٢٠، أكياس يد متوفرة».
     */
    public function test_a_line_that_is_not_short_produces_nothing(): void
    {
        // Arrange
        [$order, $short] = $this->orderOf300();
        $fine = OrderItem::factory()->for($order)->create(['quantity' => '100.000']);

        app(OrderService::class)->setShortages($order->refresh(), [
            $short->getKey() => '20',
            $fine->getKey() => null,
        ]);

        // Act
        $this->sync($order);

        // Assert
        $this->assertSame(1, Shortage::query()->where('order_id', $order->getKey())->count());
        $this->assertNull(Shortage::query()->where('order_item_id', $fine->getKey())->first());
    }

    /**
     * §١٠, the requirement this whole formula exists for.
     */
    public function test_declaring_the_same_shortage_twice_does_not_duplicate_or_double_it(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $this->declareShortage($order, $item, '30');
        $this->sync($order);

        // Act — the order is moved into «نواقص» a second time with the same number.
        $this->declareShortage($order, $item, '30');
        $this->sync($order);

        // Assert
        $shortages = Shortage::query()->where('order_item_id', $item->getKey())->get();

        $this->assertCount(1, $shortages);
        $this->assertSame('30.000', (string) $shortages->first()->required_quantity);
    }

    public function test_correcting_the_quantity_on_the_order_restates_rather_than_duplicates(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $this->declareShortage($order, $item, '30');
        $this->sync($order);

        // Act
        $this->declareShortage($order, $item, '40');
        $this->sync($order);

        // Assert
        $shortages = Shortage::query()->where('order_item_id', $item->getKey())->get();

        $this->assertCount(1, $shortages);
        $this->assertSame('40.000', (string) $shortages->first()->required_quantity);
    }

    // ── the formula under supply ────────────────────────────────────────────────────────

    /**
     * The heart of it: a supply reduces the line, the sync runs again, and the requirement does
     * **not** shrink with it. An incremental reconciliation would leave «المتبقي» at zero here
     * and hide the ten kilos still owed.
     */
    public function test_a_supply_credits_the_order_line_without_shrinking_the_requirement(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $this->declareShortage($order, $item, '30');
        $this->sync($order);

        $shortage = Shortage::query()->where('order_item_id', $item->getKey())->firstOrFail();

        // Act
        $this->buy($shortage, '20.000', '500.00');

        // Assert — the invoice got the twenty back.
        $this->assertSame('10.000', (string) $item->refresh()->shortage_quantity);

        $shortage->refresh();
        $this->assertSame('30.000', (string) $shortage->required_quantity);
        $this->assertSame('20.000', (string) $shortage->supplied_quantity);
        $this->assertSame('10.000', $shortage->remainingQuantity());
        $this->assertSame(ShortageStatus::New, $shortage->status);

        // Act — the listener fires after every such write, so running it again must be a no-op.
        $this->sync($order);

        // Assert
        $shortage->refresh();
        $this->assertSame('30.000', (string) $shortage->required_quantity);
        $this->assertSame('20.000', (string) $shortage->supplied_quantity);
        $this->assertSame(1, Shortage::query()->where('order_item_id', $item->getKey())->count());
    }

    /**
     * The invoice is the reason the write-back exists: goods bought for the customer are goods
     * the customer is billed for.
     */
    public function test_supplying_the_whole_shortage_restores_the_invoice_and_completes_it(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $before = (string) $order->grand_total;

        $this->declareShortage($order, $item, '30');
        $this->sync($order);

        $this->assertNotSame($before, (string) $order->refresh()->grand_total, 'the shortage cut the invoice');

        $shortage = Shortage::query()->where('order_item_id', $item->getKey())->firstOrFail();

        // Act
        $this->buy($shortage, '30.000', '760.00', PaymentMethod::BankTransfer);

        // Assert
        $this->assertNull($item->refresh()->shortage_quantity);
        $this->assertSame($before, (string) $order->refresh()->grand_total);

        $shortage->refresh();
        $this->assertSame('0.000', $shortage->remainingQuantity());
        $this->assertSame('760.00', (string) $shortage->total_paid);
        $this->assertSame(ShortageStatus::Completed, $shortage->status);
    }

    /**
     * A reversal is a row with a *positive* quantity, so a requirement restated with
     * `supplies()->sum()` would grow every time an entry was corrected — and the shortage would
     * ask for sixty kilos after one twenty-kilo purchase was undone.
     */
    public function test_a_reversed_purchase_does_not_inflate_the_requirement(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $this->declareShortage($order, $item, '30');
        $this->sync($order);

        $shortage = Shortage::query()->where('order_item_id', $item->getKey())->firstOrFail();

        $supply = $this->buy($shortage, '20.000', '500.00');

        app(ShortageService::class)->reverseSupply(
            $shortage->refresh(),
            $supply,
            'أُدخلت مرتين',
            $this->buyer(),
        );

        // The reversal put the twenty back on the section's books but not on the order's line —
        // see ReverseShortageSupply for why an undone entry is not an un-delivery.
        $this->declareShortage($order, $item, '30', ShortageRevision::Corrected);

        // Act
        $this->sync($order, ShortageRevision::Corrected);

        // Assert
        $shortage->refresh();
        $this->assertSame('30.000', (string) $shortage->required_quantity);
        $this->assertSame('0.000', (string) $shortage->supplied_quantity);
        $this->assertSame('0.00', (string) $shortage->total_paid);
    }

    // ── the other door ──────────────────────────────────────────────────────────────────

    /**
     * §٣٫١: a colleague closes the same shortage from the order screen, and the log has to say so
     * rather than showing a requirement that shrank on its own.
     */
    public function test_goods_arriving_through_the_order_screen_are_written_to_the_ledger(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $this->declareShortage($order, $item, '30');
        $this->sync($order);

        // Act — what the `received_{id}` field does when the order leaves «نواقص».
        $this->declareShortage($order, $item, '10', ShortageRevision::Received);
        $this->sync($order, ShortageRevision::Received);

        // Assert
        $shortage = Shortage::query()->where('order_item_id', $item->getKey())->firstOrFail();

        $this->assertSame('20.000', (string) $shortage->supplied_quantity);
        $this->assertSame('10.000', $shortage->remainingQuantity());
        // No money: goods that arrived from the order were paid for on a purchase order.
        $this->assertSame('0.00', (string) $shortage->total_paid);

        $arrival = $shortage->supplies()->firstOrFail();
        $this->assertSame(SupplyKind::ResolvedExternally, $arrival->kind);
        $this->assertSame('20.000', (string) $arrival->quantity);
        $this->assertNull($arrival->amount);
    }

    /**
     * Leaving «نواقص» with everything received — `ShortageRevision::Received`, which is what
     * `ChangeOrderStatus::recordArrivedShortages()` passes.
     *
     * The same two writes with `Corrected` mean the opposite thing and are asserted below.
     */
    public function test_the_whole_shortage_arriving_through_the_order_completes_it(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $this->declareShortage($order, $item, '30');
        $this->sync($order);

        // Act
        $this->declareShortage($order, $item, null, ShortageRevision::Received);
        $this->sync($order, ShortageRevision::Received);

        // Assert
        $shortage = Shortage::query()->where('order_item_id', $item->getKey())->firstOrFail();

        $this->assertSame('30.000', (string) $shortage->supplied_quantity);
        $this->assertSame(ShortageStatus::Completed, $shortage->status);
    }

    /**
     * A shortage declared and then taken back was never chased — it is noise on the board, not
     * history.
     */
    public function test_an_unchased_shortage_taken_back_on_the_order_is_removed(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $this->declareShortage($order, $item, '30');
        $this->sync($order);

        Shortage::query()->where('order_item_id', $item->getKey())->firstOrFail();

        // Act — corrected to «لا ينقص شيء» on the order screen, before anybody bought anything.
        $this->declareShortage($order, $item, null, ShortageRevision::Corrected);
        $this->sync($order, ShortageRevision::Corrected);

        // Assert — and it is gone rather than left reading zero.
        $this->assertNull(Shortage::query()->where('order_item_id', $item->getKey())->first());
        $this->assertSame(
            1,
            Shortage::withTrashed()->where('order_item_id', $item->getKey())->count(),
            'soft-deleted, not erased',
        );
    }

    // ── the order's own lifecycle ───────────────────────────────────────────────────────

    public function test_cancelling_an_order_stops_the_chase_but_keeps_what_was_bought(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $this->declareShortage($order, $item, '30');
        $this->sync($order);

        $chased = Shortage::query()->where('order_item_id', $item->getKey())->firstOrFail();

        $this->buy($chased, '10.000', '250.00');

        // Act
        app(CloseShortagesForOrder::class)((int) $order->getKey());

        // Assert — kept, marked as no longer chased, and the money is untouched. §٧٫٣.
        $chased->refresh();
        $this->assertSame(ShortageStatus::Unavailable, $chased->status);
        $this->assertSame('250.00', (string) $chased->total_paid);
        $this->assertSame(1, $chased->supplies()->count(), 'no reversal was written');
    }

    public function test_cancelling_an_order_removes_shortages_nobody_spent_anything_on(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $this->declareShortage($order, $item, '30');
        $this->sync($order);

        // Act
        app(CloseShortagesForOrder::class)((int) $order->getKey());

        // Assert
        $this->assertNull(Shortage::query()->where('order_item_id', $item->getKey())->first());
    }

    /**
     * «الاحتفاظ بالنواقص المكتملة كسجلٍّ تاريخي» — an explicit requirement, and the money behind
     * it really was spent.
     */
    public function test_a_completed_shortage_survives_its_order_ending(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $this->declareShortage($order, $item, '30');
        $this->sync($order);

        $shortage = Shortage::query()->where('order_item_id', $item->getKey())->firstOrFail();

        $this->buy($shortage, '30.000', '760.00');

        $this->assertSame(ShortageStatus::Completed, $shortage->refresh()->status);

        // Act
        app(CloseShortagesForOrder::class)((int) $order->getKey());

        // Assert
        $shortage->refresh();
        $this->assertSame(ShortageStatus::Completed, $shortage->status);
        $this->assertSame('760.00', (string) $shortage->total_paid);
    }

    /**
     * A manual shortage has no order lines to be swept by, and must survive an order edit that
     * happens to run the sweep.
     */
    public function test_a_manual_shortage_is_untouched_by_an_orders_sweep(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $manual = Shortage::factory()->create();

        // Act
        app(CloseShortagesForOrder::class)(
            (int) $order->getKey(),
            [(int) $item->getKey()],
        );

        // Assert
        $this->assertNotNull(Shortage::query()->whereKey($manual->getKey())->first());
    }

    // ── the archive ─────────────────────────────────────────────────────────────────────

    /**
     * §٥: these rows name an order and a customer, so the archive leaks through them unless the
     * list is scoped by the reader's grant.
     */
    public function test_a_reader_without_the_archive_grant_sees_nothing_of_a_deleted_orders_shortage(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $this->declareShortage($order, $item, '30');
        $this->sync($order);

        $shortage = Shortage::query()->where('order_item_id', $item->getKey())->firstOrFail();
        $order->delete();

        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::ViewShortages->value);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        // Act
        $list = $this->getJson('/api/v1/shortages', $headers);
        $counts = $this->getJson('/api/v1/shortages/summary', $headers);
        $detail = $this->getJson("/api/v1/shortages/{$shortage->getKey()}", $headers);

        // Assert — and the chip row must agree with the list, or the archive leaks through a
        // number that moves.
        $list->assertOk()->assertJsonCount(0, 'data');
        $counts->assertOk()->assertJsonPath('data.counts.new', 0)->assertJsonPath('data.total', 0);
        $detail->assertForbidden();
    }

    public function test_the_archive_grant_opens_it(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $this->declareShortage($order, $item, '30');
        $this->sync($order);

        $shortage = Shortage::query()->where('order_item_id', $item->getKey())->firstOrFail();
        $order->delete();

        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewShortages->value,
            PermissionName::ViewOrderArchive->value,
        ]);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        // Act
        $list = $this->getJson('/api/v1/shortages', $headers);
        $detail = $this->getJson("/api/v1/shortages/{$shortage->getKey()}", $headers);

        // Assert
        $list->assertOk()->assertJsonCount(1, 'data');
        $detail->assertOk()->assertJsonPath('data.order.is_archived', true);
    }

    /**
     * A manual shortage has no order, so `whereDoesntHave` must let it through rather than
     * treating "no order" as "an archived one".
     */
    public function test_a_manual_shortage_is_visible_without_the_archive_grant(): void
    {
        // Arrange
        Shortage::factory()->create();

        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::ViewShortages->value);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        // Act
        $response = $this->getJson('/api/v1/shortages', $headers);

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data');
    }

    // ── editing ─────────────────────────────────────────────────────────────────────────

    /**
     * An order-born shortage takes its numbers from the line, so an edit here would be
     * overwritten by the next sync — refused rather than silently undone.
     */
    public function test_an_order_born_shortage_cannot_be_edited_in_the_section(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $this->declareShortage($order, $item, '30');
        $this->sync($order);

        $shortage = Shortage::query()->where('order_item_id', $item->getKey())->firstOrFail();

        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewShortages->value,
            PermissionName::ManageShortages->value,
        ]);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        // Act
        $response = $this->putJson("/api/v1/shortages/{$shortage->getKey()}", [
            'name' => 'شيء آخر',
            'unit' => 'piece',
            'required_quantity' => '5',
        ], $headers);

        // Assert
        $response->assertStatus(422);
        $this->assertSame('30.000', (string) $shortage->refresh()->required_quantity);
    }
}
