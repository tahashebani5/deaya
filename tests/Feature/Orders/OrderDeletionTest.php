<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Carrier\CarrierService;
use App\Domain\Carrier\Models\NawrisParcel;
use App\Domain\Carrier\Models\NawrisParcelOrder;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\DTOs\StockMovementData;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Order\Actions\ChangeOrderStatus;
use App\Domain\Order\Actions\DeleteOrder;
use App\Domain\Order\Actions\RecalculateOrderTotals;
use App\Domain\Order\Actions\RecordOrderPayment;
use App\Domain\Order\Actions\RecordStatusTransition;
use App\Domain\Order\Actions\ResolveOrderFlow;
use App\Domain\Order\Actions\RestoreOrder;
use App\Domain\Order\Actions\ReverseOrderPayment;
use App\Domain\Order\DTOs\OrderPaymentData;
use App\Domain\Order\Enums\ManufacturingCostType;
use App\Domain\Order\Enums\OrderFlow;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Exceptions\FulfillmentWarehouseIsDeleted;
use App\Domain\Order\Exceptions\OrderHasAnOpenParcel;
use App\Domain\Order\Exceptions\OrderIsAlreadyDeleted;
use App\Domain\Order\Exceptions\OrderIsDeletedForStatusChange;
use App\Domain\Order\Exceptions\OrderIsNotDeleted;
use App\Domain\Order\Exceptions\OrderStockShortfall;
use App\Domain\Order\Models\ManufacturingCostRate;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderDesign;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderPayment;
use App\Domain\Order\Models\OrderStatusTransition;
use App\Domain\Order\Models\ProductionCostEntry;
use App\Domain\Order\Support\StockEffectPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «حذف» as against «إلغاء تام» — see Docs/orders/ORDER-DELETE-AND-ARCHIVE.md §١.
 *
 * A cancellation says the order happened and then ended; a delete says it should never have been
 * written down. That difference is the whole of what is asserted here:
 *
 * > a delete gives the goods back **and remembers that it did**, so that a restore can put them
 * > out again exactly — where a reinstatement, which undoes a real business event, deliberately
 * > does not.
 *
 * Everything that could make either half lie is pinned: a parcel whose `closed_at` would never
 * be written again, a shelf that has been retired underneath the order, a cancelled order whose
 * goods are already back, and the `total_cogs` that no longer matches the `order_items.cogs`
 * beneath it. The money the delete reverses is {@see OrderDeletionMoneyTest}'s, which owns §٢٫١
 * and §٧٫١ whole.
 *
 * Driven through the domain actions rather than the API, because the routes and the permissions
 * behind them are being built in parallel — the rules asserted here are the domain's own.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        return User::factory()->create();
    }

    /** Puts `$quantity` of `$variant` on `$warehouse`'s shelf at `$unitCost` each. */
    private function stockUp(Warehouse $warehouse, ProductVariant $variant, string $quantity, string $unitCost, User $actor): void
    {
        app(InventoryService::class)->recordMovement(StockMovementData::arrival([
            'stock_item_id' => $variant->stock_item_id,
            'to_warehouse_id' => $warehouse->getKey(),
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
        ], (int) $actor->getKey()));
    }

    private function balanceOf(Warehouse $warehouse, ProductVariant $variant): string
    {
        return (string) (WarehouseStock::query()
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_item_id', $variant->stock_item_id)
            ->first()?->quantity ?? '0.000');
    }

    /**
     * An order of 300 whose stock has actually left `$warehouse` — the state every interesting
     * case below starts from.
     *
     * Walked through {@see ChangeOrderStatus} rather than force-filled, because
     * `fulfillment_stock_movement_id` and the cost layers behind it are what the delete reads,
     * and a hand-written row would not have them.
     *
     * @return array{Order, OrderItem}
     */
    private function deductedOrder(Warehouse $warehouse, ProductVariant $variant, User $actor, bool $throughToReady = false): array
    {
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->getKey(),
            'quantity' => '300.000',
        ]);

        app(ChangeOrderStatus::class)(
            $order->refresh(),
            OrderStatus::ReadyToPrint,
            null,
            $actor,
            ['warehouse_id' => $warehouse->getKey()],
        );

        if ($throughToReady) {
            app(ChangeOrderStatus::class)($order->refresh(), OrderStatus::Printing, null, $actor);
            app(ChangeOrderStatus::class)($order->refresh(), OrderStatus::Ready, null, $actor);
        }

        return [$order->refresh(), $item->refresh()];
    }

    // ---------------------------------------------------------------- deleting

    public function test_deleting_gives_the_stock_back_and_records_that_it_did(): void
    {
        // Arrange — 500 on the shelf, 300 of them drawn by an order
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        $this->stockUp($warehouse, $variant, '500', '5', $actor);
        [$order] = $this->deductedOrder($warehouse, $variant, $actor);

        $this->assertSame('200.000', $this->balanceOf($warehouse, $variant));

        // Act
        $deleted = app(DeleteOrder::class)($order, $actor);

        // Assert — the goods are back, the order is in the archive, and the order itself carries
        // the fact that makes a restore an exact undo rather than a guess.
        $this->assertSame('500.000', $this->balanceOf($warehouse, $variant));
        $this->assertTrue($deleted->trashed());
        $this->assertNotNull($deleted->delete_returned_stock_at);
        $this->assertSame(1, StockMovement::query()->where('movement_type', 'order_reversal')->count());
    }

    public function test_a_second_delete_is_refused_rather_than_breaking_the_ledger(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        $this->stockUp($warehouse, $variant, '500', '5', $actor);
        [$order] = $this->deductedOrder($warehouse, $variant, $actor);
        app(DeleteOrder::class)($order, $actor);

        // Act / Assert — an idempotent double-tap is a sentence, not a unique-index violation
        $this->expectException(OrderIsAlreadyDeleted::class);

        app(DeleteOrder::class)($order->refresh(), $actor);
    }

    public function test_an_order_still_out_with_the_carrier_is_refused(): void
    {
        // Arrange — a parcel with no `closed_at`, which is what «ما زال في الطريق» means
        $order = Order::factory()->create();
        $parcel = NawrisParcel::factory()->create();
        NawrisParcelOrder::factory()->create([
            'nawris_parcel_id' => $parcel->getKey(),
            'order_id' => $order->getKey(),
        ]);
        $actor = $this->actor();

        // Act / Assert
        $this->expectException(OrderHasAnOpenParcel::class);

        app(DeleteOrder::class)($order, $actor);
    }

    public function test_deleting_a_cancelled_order_does_not_hand_the_goods_back_twice(): void
    {
        // Arrange — the cancellation has already credited the whole draw back
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        $this->stockUp($warehouse, $variant, '500', '5', $actor);
        [$order] = $this->deductedOrder($warehouse, $variant, $actor);

        app(ChangeOrderStatus::class)($order->refresh(), OrderStatus::Cancelled, 'العميل تراجع', $actor);
        $this->assertSame('500.000', $this->balanceOf($warehouse, $variant));

        // Act
        $deleted = app(DeleteOrder::class)($order->refresh(), $actor);

        // Assert — `stock_deducted_at` still says stock left this order once and is never
        // cleared, so reading it here would have credited a second 300 onto the shelf and hit
        // `stock_movements_reverses_movement_id_unique` on the way. The ledger says otherwise.
        $this->assertSame('500.000', $this->balanceOf($warehouse, $variant));
        $this->assertSame(1, StockMovement::query()->where('movement_type', 'order_reversal')->count());
        $this->assertNull($deleted->delete_returned_stock_at);
        $this->assertTrue($deleted->trashed());
    }

    public function test_deleting_is_refused_when_the_fulfilment_warehouse_has_been_retired(): void
    {
        // Arrange — the shelf the order drew on is soft-deleted underneath it
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        $this->stockUp($warehouse, $variant, '500', '5', $actor);
        [$order] = $this->deductedOrder($warehouse, $variant, $actor);
        $warehouse->delete();

        // Act / Assert — refused by name, rather than growing a live balance row nobody can reach
        $this->expectException(FulfillmentWarehouseIsDeleted::class);

        app(DeleteOrder::class)($order->refresh(), $actor);
    }

    public function test_the_credit_back_is_a_reversal_that_names_the_draw_it_undoes(): void
    {
        // Arrange — 300 out of a shelf of 500, through the real status path so the line carries
        // the `fulfillment_stock_movement_id` this test is about
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        $this->stockUp($warehouse, $variant, '500', '5', $actor);
        [$order, $item] = $this->deductedOrder($warehouse, $variant, $actor);
        $draw = $item->refresh()->fulfillment_stock_movement_id;

        // Act
        app(DeleteOrder::class)($order, $actor);

        // Assert — **the pointer, not just the count.** A credit-back that put 300 bags on the
        // shelf without naming the draw it undoes would balance the warehouse and still leave the
        // ledger unable to answer «أيّ خصمٍ رجع؟» — and it is that pointer, through
        // `reversedBy`, that {@see Order::linesWithStockStillDrawn()} reads to decide a second
        // delete has nothing left to return. A `movement_type` assertion alone passes on a bare
        // arrival typed as a reversal.
        $reversal = StockMovement::query()->where('reverses_movement_id', $draw)->first();

        $this->assertNotNull($reversal, 'The credit-back must point at the draw it reverses.');
        $this->assertSame('order_reversal', $reversal->movement_type->value);
        $this->assertSame('300.000', (string) $reversal->quantity);
        $this->assertSame($warehouse->getKey(), $reversal->to_warehouse_id);
        $this->assertSame((int) $actor->getKey(), $reversal->employee_id);
    }

    public function test_deleting_an_order_that_never_reached_the_warehouse_moves_nothing(): void
    {
        // Arrange — «جديدة»: priced, written down, and never fulfilled
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        $this->stockUp($warehouse, $variant, '500', '5', $actor);

        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->getKey(),
        ]);

        // Act
        $deleted = app(DeleteOrder::class)($order->refresh(), $actor);

        // Assert — the shelf is untouched and no stamp is written, which is what tells the
        // restore it has nothing to draw again. The arrival is the only movement in the ledger.
        $this->assertSame('500.000', $this->balanceOf($warehouse, $variant));
        $this->assertSame(0, StockMovement::query()->where('movement_type', 'order_reversal')->count());
        $this->assertNull($deleted->delete_returned_stock_at);
        $this->assertTrue($deleted->trashed());
    }

    public function test_deleting_an_outsourced_order_touches_no_shelf_of_ours(): void
    {
        // Arrange — وسيط: an outside vendor makes it, so nothing of ours was ever drawn. The road
        // is stamped by the action intake uses rather than written by hand, because
        // `ResolveOrderFlow` reads it off the lines once and never again.
        $category = ProductCategory::factory()->outsourced()->create(['name' => 'وسيط']);
        $product = Product::factory()->create(['product_category_id' => $category->getKey()]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $actor = $this->actor();

        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
        ]);
        app(ResolveOrderFlow::class)($order->load('items'));

        app(ChangeOrderStatus::class)($order->refresh(), OrderStatus::Manufacturing, null, $actor);
        app(ChangeOrderStatus::class)($order->refresh(), OrderStatus::Ready, null, $actor);

        $this->assertSame(OrderFlow::Outsourced, $order->refresh()->production_flow);

        // Act
        $deleted = app(DeleteOrder::class)($order->refresh(), $actor);

        // Assert — «جاهزة» on this road asks for no warehouse and takes nothing out of one, so
        // the delete has nothing to hand back. The whole ledger is still empty.
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertNull($deleted->delete_returned_stock_at);
        $this->assertTrue($deleted->trashed());
    }

    public function test_deleting_a_plain_bag_order_returns_what_entering_ready_drew(): void
    {
        // Arrange — كيس سادة: no design and no press, and the deduction happens on the way into
        // «جاهزة» rather than «جاهزة للطباعة». A delete that only knew the printed road would
        // find nothing to return here and leave 300 bags off the shelf for good.
        $category = ProductCategory::factory()->skipsProduction()->create(['name' => 'سادة']);
        $product = Product::factory()->create(['product_category_id' => $category->getKey()]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        $this->stockUp($warehouse, $variant, '500', '5', $actor);

        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'quantity' => '300.000',
        ]);
        app(ResolveOrderFlow::class)($order->load('items'));

        app(ChangeOrderStatus::class)(
            $order->refresh(),
            OrderStatus::Ready,
            null,
            $actor,
            ['warehouse_id' => $warehouse->getKey()],
        );

        $this->assertSame(OrderFlow::NoProduction, $order->refresh()->production_flow);
        $this->assertSame('200.000', $this->balanceOf($warehouse, $variant));

        // Act
        $deleted = app(DeleteOrder::class)($order->refresh(), $actor);

        // Assert — read from the ledger, so which status drew the goods never mattered
        $this->assertSame('500.000', $this->balanceOf($warehouse, $variant));
        $this->assertNotNull($deleted->delete_returned_stock_at);
        $this->assertSame(1, StockMovement::query()->where('movement_type', 'order_reversal')->count());
    }

    // ------------------------------------------------- the money in §٢٫١

    /**
     * The reversal the delete now writes for itself is pinned in {@see OrderDeletionMoneyTest},
     * which owns §٢٫١ whole. What is left here is the case that reaches the delete with nothing
     * to do: an entry a person already reversed at the counter.
     */
    public function test_deleting_an_order_whose_cash_was_already_reversed_writes_no_second_reversal(): void
    {
        // Arrange — money is an append-only ledger, so «reversed» means a second entry pointing
        // at the first, never an edited row. `Order::liveCreditEntries()` is what the delete
        // walks, and an entry already carrying a reversal is not in it — a second one would
        // break `order_payments_reverses_payment_id_unique`.
        $order = Order::factory()->status(OrderStatus::Delivered)->create();
        OrderItem::factory()->for($order)->create();
        $actor = $this->actor();

        app(RecalculateOrderTotals::class)($order->refresh());
        $payment = app(RecordOrderPayment::class)(
            $order->refresh(),
            OrderPaymentData::fromArray(['amount' => '150', 'method' => PaymentMethod::Cash->value]),
            $actor,
        );

        app(ReverseOrderPayment::class)($order->refresh(), $payment, 'قُيّدت على الطلبية الخطأ', $actor);
        $this->assertSame('0.00', (string) $order->refresh()->paid_amount);

        // Act
        $deleted = app(DeleteOrder::class)($order->refresh(), $actor);

        // Assert — both ledger rows survive the archive, because the P&L reads `order_payments`
        // with no join to `orders` at all, and the delete added no third one
        $this->assertTrue($deleted->trashed());
        $this->assertSame(2, OrderPayment::query()->where('order_id', $order->getKey())->count());
    }

    public function test_deleting_is_allowed_once_the_parcel_is_detached(): void
    {
        // Arrange — a parcel with no `closed_at`, which is what «ما زال في الطريق» means
        $order = Order::factory()->create();
        $parcel = NawrisParcel::factory()->create();
        NawrisParcelOrder::factory()->create([
            'nawris_parcel_id' => $parcel->getKey(),
            'order_id' => $order->getKey(),
        ]);
        $actor = $this->actor();

        try {
            app(DeleteOrder::class)($order, $actor);
            $this->fail('An order still out with the carrier should have been refused.');
        } catch (OrderHasAnOpenParcel $refusal) {
            $this->assertStringContainsString((string) $parcel->code, $refusal->getMessage());
            $this->assertStringContainsString('افصل الطرد أو ألغِه أولاً', $refusal->getMessage());
        }

        // Act — the message asks for a decision, and this is the person making it
        app(CarrierService::class)->detachParcelFrom($order->refresh());
        $deleted = app(DeleteOrder::class)($order->refresh(), $actor);

        // Assert — the refusal is about the parcel's state, not about the order having ever had
        // one, so it lifts the moment the link is gone
        $this->assertTrue($deleted->trashed());
    }

    // ------------------------------------------ the children in §٥

    public function test_archiving_an_order_leaves_all_four_of_its_children_standing(): void
    {
        // Arrange — one of each: a line, a design, a recorded transition and a pair of payment
        // entries (the second reversing the first, so the money guard has nothing to refuse)
        $order = Order::factory()->status(OrderStatus::Delivered)->create();
        $item = OrderItem::factory()->for($order)->create();
        $design = OrderDesign::factory()->for($order)->create();
        $actor = $this->actor();

        app(RecalculateOrderTotals::class)($order->refresh());
        app(RecordStatusTransition::class)($order->refresh(), OrderStatus::New, OrderStatus::Delivered, null, $actor);
        $payment = app(RecordOrderPayment::class)(
            $order->refresh(),
            OrderPaymentData::fromArray(['amount' => '100', 'method' => PaymentMethod::Cash->value]),
            $actor,
        );
        app(ReverseOrderPayment::class)($order->refresh(), $payment, 'قيد خاطئ', $actor);

        // Act
        app(DeleteOrder::class)($order->refresh(), $actor);

        // Assert — `Order` deliberately does not carry `CascadesSoftDeletes`, so every child is
        // still live and still readable through the ordinary scoped relation. This is what makes
        // the archive's detail screen show an order rather than an empty shell.
        $this->assertNull(OrderItem::query()->find($item->getKey())?->deleted_at);
        $this->assertNull(OrderDesign::query()->find($design->getKey())?->deleted_at);
        $this->assertSame(1, OrderStatusTransition::query()->where('order_id', $order->getKey())->count());
        $this->assertSame(2, OrderPayment::query()->where('order_id', $order->getKey())->count());
    }

    public function test_an_archived_cancelled_order_still_draws_its_progress_bar(): void
    {
        // Arrange — «إلغاء تام» is off the main line, and it is most of what an archive holds.
        // `Order::progress()` answers such a status by falling through to
        // `furthestMainLineStep()`, whose first statement is `transitions()->pluck('to_status')`
        // — **without `withTrashed()`**.
        //
        // Walked to «جاهزة للطباعة» and cancelled from there, which is the shape an archived
        // cancellation actually has: «جديدة» is the one open status that may not be cancelled,
        // so a real order has always got somewhere before it ends.
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        $this->stockUp($warehouse, $variant, '500', '5', $actor);
        [$order] = $this->deductedOrder($warehouse, $variant, $actor);

        app(ChangeOrderStatus::class)($order->refresh(), OrderStatus::Cancelled, 'إدخال مكرر', $actor);

        $reachedWhileLive = $order->refresh()->progress();

        // Act
        $archived = app(DeleteOrder::class)($order->refresh(), $actor);

        // Assert — the exact failure §٥ forbids: cascading `transitions` would empty that pluck,
        // `furthestMainLineStep()` would fall back to 0, and every archived order would draw a
        // bar as though nothing had ever happened to it. Asserted against the live reading rather
        // than a hard-coded shape, so the test keeps meaning if the main line itself is re-cut.
        $this->assertSame($reachedWhileLive, $archived->progress());

        // And the bar is not merely *equal* to the live one, it is a bar that got somewhere:
        // «جاهزة للطباعة» is behind this order, so more than the first step reads «done». An
        // order whose timeline had been cascaded away would pass the comparison above and fail
        // this line, because both readings would be empty together.
        $states = array_column($archived->progress()['steps'], 'state');

        $this->assertGreaterThan(1, count(array_filter($states, fn (string $s) => $s === 'done')));
        $this->assertTrue($archived->progress()['is_detour']);

        // The other half of §٥'s reason: this is what «تراجع عن الإلغاء» reads to know where to
        // put the order back, and it walks the same relation.
        $this->assertSame(OrderStatus::ReadyToPrint, $archived->statusBeforeCancellation());
    }

    // --------------------------------------------------------------- restoring

    public function test_restoring_draws_the_stock_again_and_restates_the_cost_at_todays_rates(): void
    {
        // Arrange — 300 drawn at 5 each and costed at a labour rate of 0.10, then deleted
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        $rate = ManufacturingCostRate::factory()->forProduct($product)
            ->type(ManufacturingCostType::Labor)->create(['rate_per_unit' => '0.100']);

        $this->stockUp($warehouse, $variant, '500', '5', $actor);
        [$order, $item] = $this->deductedOrder($warehouse, $variant, $actor, throughToReady: true);

        $this->assertSame('1530.00', (string) $order->refresh()->total_cogs); // 1500 material + 30 labour

        app(DeleteOrder::class)($order->refresh(), $actor);

        // The press put its wages up while the order sat in the archive.
        $rate->forceFill(['rate_per_unit' => '0.200'])->save();

        // Act
        $restored = app(RestoreOrder::class)($order->refresh(), $actor);

        // Assert — the goods are out again, and `orders.total_cogs` moved with the
        // `order_items.cogs` beneath it rather than being left on yesterday's figure
        $this->assertFalse($restored->trashed());
        $this->assertNull($restored->delete_returned_stock_at);
        $this->assertSame('200.000', $this->balanceOf($warehouse, $variant));
        $this->assertSame('60.00', (string) $item->refresh()->labor_cost);
        $this->assertSame('1560.00', (string) $item->cogs);
        $this->assertSame('1560.00', (string) $restored->total_cogs);
    }

    public function test_restoring_an_order_that_was_never_deleted_is_refused(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $actor = $this->actor();

        // Act / Assert
        $this->expectException(OrderIsNotDeleted::class);

        app(RestoreOrder::class)($order, $actor);
    }

    public function test_restoring_names_the_shelf_that_is_short(): void
    {
        // Arrange — the goods came back on the delete and were then moved elsewhere
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $elsewhere = Warehouse::factory()->create();
        $actor = $this->actor();

        $this->stockUp($warehouse, $variant, '300', '5', $actor);
        [$order] = $this->deductedOrder($warehouse, $variant, $actor);
        app(DeleteOrder::class)($order, $actor);

        app(InventoryService::class)->recordMovement(StockMovementData::transfer([
            'stock_item_id' => $variant->stock_item_id,
            'from_warehouse_id' => $warehouse->getKey(),
            'to_warehouse_id' => $elsewhere->getKey(),
            'quantity' => '300',
        ], (int) $actor->getKey()));

        // Act / Assert — the storekeeper is told which pile, not «٠ لا تكفي ٣٠٠»
        try {
            app(RestoreOrder::class)($order->refresh(), $actor);
            $this->fail('A restore onto an empty shelf should have been refused.');
        } catch (InsufficientStock $bare) {
            $this->fail('The bare two-number message reached the user: '.$bare->getMessage());
        } catch (OrderStockShortfall $shortfall) {
            $this->assertStringContainsString('لا تكفي للكمية المطلوبة', $shortfall->getMessage());
        }

        // And nothing moved: the whole restore is one transaction
        $this->assertTrue($order->refresh()->trashed());
        $this->assertSame('0.000', $this->balanceOf($warehouse, $variant));
    }

    public function test_a_short_restore_names_every_pile_at_once_rather_than_the_first(): void
    {
        // Arrange — **two** sizes on the order and both shelves emptied while it sat in the
        // archive. One short size proves the message; two prove the aggregation, which is the
        // thing that actually differs from `InsufficientStock`: that exception is thrown by the
        // balance one movement at a time, so the storekeeper would be told about the first pile,
        // restock it, press restore again and be told about the second.
        $product = Product::factory()->create();
        $small = ProductVariant::factory()->create(['product_id' => $product->getKey(), 'label' => '25*35']);
        $large = ProductVariant::factory()->create(['product_id' => $product->getKey(), 'label' => '30*40']);
        $warehouse = Warehouse::factory()->create();
        $elsewhere = Warehouse::factory()->create();
        $actor = $this->actor();

        $this->stockUp($warehouse, $small, '300', '5', $actor);
        $this->stockUp($warehouse, $large, '300', '7', $actor);

        $order = Order::factory()->create();
        foreach ([$small, $large] as $variant) {
            OrderItem::factory()->for($order)->create([
                'product_id' => $product->getKey(),
                'product_variant_id' => $variant->getKey(),
                'variant_label' => $variant->label,
                'quantity' => '300.000',
            ]);
        }

        app(ChangeOrderStatus::class)(
            $order->refresh(),
            OrderStatus::ReadyToPrint,
            null,
            $actor,
            ['warehouse_id' => $warehouse->getKey()],
        );

        app(DeleteOrder::class)($order->refresh(), $actor);

        foreach ([$small, $large] as $variant) {
            app(InventoryService::class)->recordMovement(StockMovementData::transfer([
                'stock_item_id' => $variant->stock_item_id,
                'from_warehouse_id' => $warehouse->getKey(),
                'to_warehouse_id' => $elsewhere->getKey(),
                'quantity' => '300',
            ], (int) $actor->getKey()));
        }

        // Act / Assert
        try {
            app(RestoreOrder::class)($order->refresh(), $actor);
            $this->fail('A restore onto two empty shelves should have been refused.');
        } catch (InsufficientStock $bare) {
            $this->fail('The bare two-number message reached the user: '.$bare->getMessage());
        } catch (OrderStockShortfall $shortfall) {
            $this->assertSame('لا يوجد رصيد كافٍ في المخزن للمواد التالية', $shortfall->getMessage());

            // Both piles named under it — by the *shelf's* name rather than the line's label,
            // which is right for this message and worth saying: a shortfall is about a pile, and
            // two sizes of two products can be drawing on one. Read back from the database rather
            // than written out here, so the assertion cannot pass by naming a string nobody uses.
            $lines = $shortfall->fieldErrors()['fields.warehouse_id'];
            $listed = implode(' ', $lines);

            $this->assertCount(2, $lines);
            $this->assertStringContainsString((string) $small->stockItem->name, $listed);
            $this->assertStringContainsString((string) $large->stockItem->name, $listed);
            $this->assertStringContainsString('المتوفر (0.000) والمطلوب (300.000)', $listed);
        }

        // And the whole restore is one transaction: the order is still archived and no shelf moved
        $this->assertTrue($order->refresh()->trashed());
        $this->assertSame('0.000', $this->balanceOf($warehouse, $small));
        $this->assertSame('0.000', $this->balanceOf($warehouse, $large));
    }

    public function test_restoring_an_order_whose_delete_returned_nothing_touches_no_stock(): void
    {
        // Arrange — an order that never reached the warehouse at all
        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create();
        $actor = $this->actor();

        app(DeleteOrder::class)($order, $actor);

        // Act
        $restored = app(RestoreOrder::class)($order->refresh(), $actor);

        // Assert
        $this->assertFalse($restored->trashed());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_the_purchase_stamp_is_left_to_the_fresh_draw(): void
    {
        // Arrange — a line carrying a purchase stamp from the draw it made before the delete
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        $this->stockUp($warehouse, $variant, '500', '5', $actor);
        [$order, $item] = $this->deductedOrder($warehouse, $variant, $actor);
        $item->forceFill(['stock_purchased_at' => now()])->save();

        app(DeleteOrder::class)($order->refresh(), $actor);

        // Act
        app(RestoreOrder::class)($order->refresh(), $actor);

        // Assert — **the stamp is not carried over, and that is the fix rather than the bug.**
        // It says what the draw the line points at bought, and after a restore the line points
        // at a new draw: this warehouse holds the company's own stock, so the repeat draw meets
        // no priced layer and `DeductOrderStock` leaves the column null. Carrying the old stamp
        // here would make `PostDealStockPurchases` recompute a payment against a draw that
        // reaches none of that deal's layers and reverse the سعر السادة the investor was already
        // paid — losing him the goods and the money both. See RestoreOrder's docblock.
        $this->assertNull($item->refresh()->stock_purchased_at);
    }

    public function test_the_stamp_the_investor_was_paid_against_does_not_survive_the_round_trip(): void
    {
        // Arrange — a deal-funded line: the day the goods left, its layers were bought off a
        // deal and the investor was paid for them. `stock_purchased_at` is the record of that
        // day, and `ReverseOrderStockDeduction` reads it to decide
        // `purchasedLayersBelongToTheCompany`.
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        $this->stockUp($warehouse, $variant, '500', '5', $actor);
        [$order, $item] = $this->deductedOrder($warehouse, $variant, $actor);

        $boughtOn = now()->subDays(9)->startOfSecond();
        $item->forceFill(['stock_purchased_at' => $boughtOn])->save();

        app(DeleteOrder::class)($order->refresh(), $actor);

        // Act
        app(RestoreOrder::class)($order->refresh(), $actor);

        // Assert — **gone, not carried and not re-stamped with today.** The delete's own
        // credit-back handed those priced layers to the company at what it paid for them —
        // «استلم الزبون ما استلمش، المطبعة تتحمّل» — and the investor kept his money: his sale
        // completed. So the repeat draw meets no priced layer, and the line is holding the
        // company's goods now.
        //
        // Keeping `$boughtOn` would have the line go on naming a purchase whose goods it no
        // longer holds, which is wrong in both places the column is read: `PostDealStockPurchases`
        // treats a second posting as a correction of the first and would reverse the سعر السادة
        // already paid, and `ReverseOrderStockDeduction` would hand the goods to the company on a
        // later cancellation for a line that bought nothing from anybody. Re-stamping with today
        // would be a third wrong answer — a purchase that never happened.
        $this->assertNull($item->refresh()->stock_purchased_at);
    }

    public function test_a_stamp_the_fresh_draw_earned_is_left_where_it_lands(): void
    {
        // Arrange — the mirror of the case above, and the reason that method only ever *restores*
        // a stamp and never clears one: a line that had no purchase behind it before the delete
        // must be free to acquire one now, because a draw that does eat a deal's layers today is
        // a real purchase the investor is owed for.
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        $this->stockUp($warehouse, $variant, '500', '5', $actor);
        [$order, $item] = $this->deductedOrder($warehouse, $variant, $actor);

        $this->assertNull($item->refresh()->stock_purchased_at);

        app(DeleteOrder::class)($order->refresh(), $actor);
        $item->forceFill(['stock_purchased_at' => null])->save();

        // Act
        app(RestoreOrder::class)($order->refresh(), $actor);

        // Assert — whatever `DeductOrderStock` wrote stands; nothing puts a stale value back over
        // it. On unpriced company stock that is null, and null is the honest answer.
        $this->assertNull($item->refresh()->stock_purchased_at);
    }

    // ------------------------------------------------- the race in §٤

    public function test_a_deleted_order_cannot_be_moved_to_another_status(): void
    {
        // Arrange
        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create();
        $actor = $this->actor();

        app(DeleteOrder::class)($order, $actor);

        // Act / Assert — without this an archived order still draws stock and pays an investor
        // for it, and no index catches it because a deduction has no reversal to collide with.
        $this->expectException(OrderIsDeletedForStatusChange::class);

        app(ChangeOrderStatus::class)(
            Order::withTrashed()->findOrFail($order->getKey()),
            OrderStatus::ReadyToPrint,
            null,
            $actor,
        );
    }

    // ------------------------------------------------ the warning in §٧
    //
    // The `stock` half of the envelope. Its `money` half — the section §٧٫١ puts *first*, and
    // the sentence saying a restore does not bring the payments back — belongs to
    // {@see OrderDeletionMoneyTest} beside the reversal it describes.

    public function test_the_preview_of_a_live_order_names_what_would_come_back(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        $this->stockUp($warehouse, $variant, '500', '5', $actor);
        [$order, $item] = $this->deductedOrder($warehouse, $variant, $actor);

        // Act
        $preview = StockEffectPreview::for($order->refresh());

        // Assert
        $this->assertSame('return', $preview['stock']['kind']);
        $this->assertSame('سيُعاد إلى المخزن ما خصمته هذه الطلبية:', $preview['stock']['warning']);
        $this->assertNull($preview['stock']['note']);
        $this->assertCount(1, $preview['stock']['lines']);
        // The product as well as the size: two products in one size used to draw two rows both
        // reading «25*35». See {@see StockEffectPreview::lines()}.
        $this->assertSame("{$item->product_name} — 25*35", $preview['stock']['lines'][0]['label']);
        $this->assertSame('300', $preview['stock']['lines'][0]['quantity']);
        $this->assertSame('قطعة', $preview['stock']['lines'][0]['unit']);
    }

    public function test_the_preview_of_an_order_that_never_drew_stock_says_nothing_moves(): void
    {
        // Arrange
        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create();

        // Act
        $preview = StockEffectPreview::for($order);

        // Assert
        $this->assertSame('none', $preview['stock']['kind']);
        $this->assertSame([], $preview['stock']['lines']);
        $this->assertNull($preview['stock']['note']);
    }

    public function test_the_preview_of_a_deleted_order_warns_that_the_cost_may_change(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        $this->stockUp($warehouse, $variant, '500', '5', $actor);
        [$order] = $this->deductedOrder($warehouse, $variant, $actor);
        $deleted = app(DeleteOrder::class)($order, $actor);

        // Act
        $preview = StockEffectPreview::for($deleted);

        // Assert — the price of a restore, said out loud rather than discovered afterwards
        $this->assertSame('rededuct', $preview['stock']['kind']);
        $this->assertSame('سيُخصم من المخزن من جديد:', $preview['stock']['warning']);
        $this->assertSame(
            'وقد تختلف تكلفة الطلبية عمّا كانت، لأن الخصم الجديد يأكل طبقات اليوم',
            $preview['stock']['note'],
        );
        $this->assertCount(1, $preview['stock']['lines']);
    }

    public function test_the_delete_voids_the_production_entries_and_the_restore_writes_new_ones(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        ManufacturingCostRate::factory()->forProduct($product)
            ->type(ManufacturingCostType::Labor)->create(['rate_per_unit' => '0.100']);

        $this->stockUp($warehouse, $variant, '500', '5', $actor);
        [$order, $item] = $this->deductedOrder($warehouse, $variant, $actor, throughToReady: true);

        // Act
        app(DeleteOrder::class)($order->refresh(), $actor);
        app(RestoreOrder::class)($order->refresh(), $actor);

        // Assert — a ledger that is added to and reversed, never edited: the original entry, its
        // reversal, and the fresh one the restore booked at today's rate.
        $this->assertSame(3, ProductionCostEntry::query()->where('order_item_id', $item->getKey())->count());
        $this->assertSame(
            1,
            ProductionCostEntry::query()
                ->where('order_item_id', $item->getKey())
                ->whereNull('reverses_entry_id')
                ->whereDoesntHave('reversal')
                ->count(),
        );
    }
}
