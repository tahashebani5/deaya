<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Enums\PricingMode;
use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Order\Actions\ResolveOrderFlow;
use App\Domain\Order\Enums\OrderFlow;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\UndeliveredDisposition;
use App\Domain\Order\Events\OrderStockDrawn;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The customer takes part of the order and leaves the rest.
 *
 * Two things have to be true at once, and they are different for different lines: the invoice
 * follows what was handed over, and the leftover goes back on a shelf if anybody else could buy
 * it and is a named loss if nobody could. See Docs/orders/PARTIAL-DELIVERY-DESIGN.md.
 *
 * Arrange - Act - Assert throughout.
 */
class PartialDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    // ───────────────────────────────── fixtures ─────────────────────────────────

    /** @return array<string, string> */
    private function auth(PermissionName ...$permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (PermissionName $p) => $p->value, $permissions));

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** Someone allowed to walk an order to «تم الاستلام» and record a partial one there. */
    private function clerk(): array
    {
        return $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ManageOrders,
            PermissionName::MoveOrderToReadyToPrint,
            PermissionName::MoveOrderToPrinting,
            PermissionName::MoveOrderToManufacturing,
            PermissionName::MoveOrderToReady,
            PermissionName::DispatchOrders,
            PermissionName::MarkOrdersDelivered,
            PermissionName::RecordPartialDelivery,
            PermissionName::ViewInventory,
            PermissionName::ManageInventory,
        );
    }

    /** «سادة» — goods already made, picked off a shelf. */
    private function blankCategory(): ProductCategory
    {
        return ProductCategory::factory()->skipsProduction()->create(['name' => 'سادة']);
    }

    /** «مطبوعة» — goods the press has to run for. */
    private function printedCategory(): ProductCategory
    {
        return ProductCategory::factory()->create(['name' => 'مطبوعة']);
    }

    /** «وسيط» — goods دعاية sells and an outside vendor makes. */
    private function outsourcedCategory(): ProductCategory
    {
        return ProductCategory::factory()->outsourced()->create(['name' => 'وسيط']);
    }

    private function sizeUnder(
        ProductCategory $category,
        PricingUnit $sold = PricingUnit::Piece,
        ?PricingUnit $stockUnit = null,
    ): ProductVariant {
        $product = Product::factory()->create([
            'product_category_id' => $category->getKey(),
            'pricing_unit' => $sold,
            'pricing_mode' => PricingMode::Tiered,
            'min_order_quantity' => '100',
        ]);

        $variant = ProductVariant::factory()->for($product)->create(array_filter([
            'label' => '25*35',
            'stock_item_id' => $stockUnit === null
                ? null
                : StockItem::factory()->unit($stockUnit)->create()->getKey(),
        ]));

        ProductPriceTier::factory()->create([
            'product_variant_id' => $variant->getKey(),
            'min_quantity' => '100',
            'unit_price' => '1.100',
        ]);

        return $variant;
    }

    /** @param  list<ProductVariant>  $sizes */
    private function stock(array $sizes, Warehouse $warehouse, array $headers): void
    {
        foreach ($sizes as $size) {
            $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
                'stock_item_id' => $size->stock_item_id,
                'to_warehouse_id' => $warehouse->getKey(),
                'quantity' => 1000,
                'unit_cost' => 4,
            ])->assertCreated();
        }
    }

    private function balanceOf(Warehouse $warehouse, ProductVariant $variant): string
    {
        return (string) (WarehouseStock::query()
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_item_id', $variant->stock_item_id)
            ->first()?->quantity ?? '0.000');
    }

    /**
     * An order standing at «جاهزة» — really deducted, really costed — with one line per size.
     *
     * Walked through the endpoint rather than force-filled, because everything under test here
     * depends on `material_cost` and `cogs` being what a real fulfilment wrote.
     *
     * @param  list<ProductVariant>  $sizes
     * @return array{Order, Warehouse}
     */
    private function readyOrder(array $sizes, array $headers, string $quantity = '300'): array
    {
        $warehouse = Warehouse::factory()->create();
        $this->stock(array_values(array_filter($sizes, fn (ProductVariant $s) => $s->stock_item_id !== null)), $warehouse, $headers);

        // **Collected at the counter, deliberately.** The dispatch pair resolves from the
        // order's own address — see `ChangeOrderStatus::resolve()` — so an ordinary delivery
        // order would land on «جاري التوصيل» and be asked for a carrier this feature has no
        // opinion about. A partial delivery is a counter event before it is anything else.
        $order = Order::factory()->officePickup()->create();

        foreach ($sizes as $size) {
            OrderItem::factory()->for($order)->create([
                'product_id' => $size->product_id,
                'product_variant_id' => $size->getKey(),
                'variant_label' => $size->label,
                'quantity' => $quantity,
                'pricing_unit' => $size->product->pricing_unit,
                'unit_price' => '1.100',
                'line_total' => bcmul('1.100', $quantity, 2),
            ]);
        }

        app(ResolveOrderFlow::class)($order->load('items'));

        $flow = $order->refresh()->production_flow;

        // Each road reaches «جاهزة» its own way — see `OrderStatus::allowedNext()`. The وسيط one
        // has no press and no shelf of ours; the plain one skips the press entirely.
        $road = match ($flow) {
            OrderFlow::Standard => [OrderStatus::ReadyToPrint, OrderStatus::Printing, OrderStatus::Ready],
            OrderFlow::Outsourced => [OrderStatus::Manufacturing, OrderStatus::Ready],
            OrderFlow::NoProduction => [OrderStatus::Ready],
        };

        foreach ($road as $step) {
            // The warehouse is named once, on whichever move actually draws — which is
            // «جاهزة للطباعة» on the printed road and «جاهزة» on the plain one. A وسيط order
            // never names one: nothing of ours is on a shelf for it.
            $drawsHere = $flow->deductsStock()
                && $order->refresh()->stock_deducted_at === null
                && ($step === OrderStatus::ReadyToPrint || $step === OrderStatus::Ready);

            $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", array_filter([
                'status' => $step->value,
                'fields' => $drawsHere ? ['warehouse_id' => $warehouse->getKey()] : null,
            ]))->assertOk();
        }

        return [$order->refresh(), $warehouse];
    }

    /** Hands the order over, with a per-line count of what the customer actually took. */
    private function deliver(Order $order, array $headers, array $fields = [])
    {
        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::OfficePickup->value,
        ])->assertOk();

        return $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Delivered->value,
            'fields' => $fields,
        ]);
    }

    // ─────────────────────────── سادة — back on the shelf ───────────────────────────

    public function test_plain_goods_left_behind_go_back_to_the_warehouse_and_off_the_invoice(): void
    {
        // Arrange — 300 plain bags at 1.100, drawn from a shelf of 1000 at cost 4.
        $headers = $this->clerk();
        $size = $this->sizeUnder($this->blankCategory());
        [$order, $warehouse] = $this->readyOrder([$size], $headers);
        $item = $order->items()->sole();

        $this->assertSame('700.000', $this->balanceOf($warehouse, $size));
        $this->assertSame('1200.00', (string) $item->material_cost); // 300 @ 4

        // Act — the customer takes 200 and leaves 100.
        $this->deliver($order, $headers, [$this->key($item) => '200'])->assertOk();

        // Assert — the invoice follows what was taken.
        $item->refresh();
        $this->assertSame('100.000', (string) $item->undelivered_quantity);
        $this->assertSame(UndeliveredDisposition::Restocked, $item->undelivered_disposition);
        $this->assertSame('200.000', $item->billableQuantity());
        $this->assertSame('220.00', (string) $item->line_total);   // 200 @ 1.100
        $this->assertSame('220.00', (string) $order->refresh()->items_total);

        // …and the goods follow the customer: 100 back on the shelf, costed off the line.
        $this->assertSame('800.000', $this->balanceOf($warehouse, $size));
        $this->assertSame('800.00', (string) $item->material_cost); // 200 @ 4
        $this->assertSame('800.00', (string) $order->total_cogs);

        // No loss: we have the bags.
        $this->assertNull($item->deliveryLoss());
        $this->assertDatabaseMissing('production_cost_entries', [
            'order_item_id' => $item->getKey(),
            'cost_type' => 'delivery_loss',
        ]);
    }

    public function test_the_restock_lands_on_the_original_cost_layers_not_an_average(): void
    {
        // Arrange — two arrivals at different costs, so an averaged credit would be visible.
        $headers = $this->clerk();
        $size = $this->sizeUnder($this->blankCategory());
        $warehouse = Warehouse::factory()->create();

        foreach ([['quantity' => 200, 'unit_cost' => 4], ['quantity' => 500, 'unit_cost' => 10]] as $arrival) {
            $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
                'stock_item_id' => $size->stock_item_id,
                'to_warehouse_id' => $warehouse->getKey(),
            ] + $arrival)->assertCreated();
        }

        $order = Order::factory()->officePickup()->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $size->product_id,
            'product_variant_id' => $size->getKey(),
            'quantity' => '300',
            'unit_price' => '1.100',
            'line_total' => '330.00',
        ]);
        app(ResolveOrderFlow::class)($order->load('items'));

        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Ready->value,
            'fields' => ['warehouse_id' => $warehouse->getKey()],
        ])->assertOk();

        $item = $order->items()->sole();
        // FIFO: 200 @ 4 then 100 @ 10 = 1800.
        $this->assertSame('1800.00', (string) $item->material_cost);

        // Act — the customer takes 200 of the 300.
        $this->deliver($order->refresh(), $headers, [$this->key($item) => '200'])->assertOk();

        // Assert — the whole draw was credited and 200 re-drawn against the *same* layers:
        // 200 @ 4 = 800. An averaged credit would have left 1200 (200 × 6).
        $this->assertSame('800.00', (string) $item->refresh()->material_cost);
    }

    /**
     * **«كم كيلو رجع؟» سؤالٌ لا يجيبه سطر البند.**
     *
     * البند يقول «غير مُستلَم ١٠٠ قطعة» — بوحدة البيع، لأنها ما عدّه الزبون. أما ما وُضع على
     * الرفّ فبوحدة المخزن، وهو ما تمحوه إعادة السحب: `warehouse_quantity` يُكتب فوقه بما بقي،
     * فالكمية التي خرجت أصلاً تضيع من السطر ولا تبقى إلا في حركتَي المخزن.
     *
     * فيُكتب الرقم لحظة وقوعه.
     */
    public function test_what_went_back_on_the_shelf_is_recorded_on_the_line(): void
    {
        // Arrange — 300 plain bags drawn off a shelf, sold and stocked in the same unit.
        $headers = $this->clerk();
        $size = $this->sizeUnder($this->blankCategory());
        [$order] = $this->readyOrder([$size], $headers);
        $item = $order->items()->sole();

        // Act — the customer takes 200 and leaves 100.
        $this->deliver($order, $headers, [$this->key($item) => '200'])->assertOk();

        // Assert — and it is what the shelf received, not what the invoice dropped: the two
        // agree here only because the line is sold in the unit it is stocked in.
        $this->assertSame('100.000', (string) $item->refresh()->restocked_quantity);
    }

    public function test_a_line_delivered_whole_puts_nothing_back_and_says_so_with_null(): void
    {
        // Arrange
        $headers = $this->clerk();
        $size = $this->sizeUnder($this->blankCategory());
        [$order] = $this->readyOrder([$size], $headers);
        $item = $order->items()->sole();

        // Act — the whole line, which is what the pre-filled box sends back.
        $this->deliver($order, $headers, [$this->key($item) => '300'])->assertOk();

        // Assert — «لم يرجع شيء» is null, never «رجع صفر»: nobody opened the shelf.
        $this->assertNull($item->refresh()->restocked_quantity);
    }

    // ───────────────────────── مطبوع — a named loss ─────────────────────────

    public function test_printed_goods_left_behind_become_a_loss_and_move_no_stock(): void
    {
        // Arrange
        $headers = $this->clerk();
        $size = $this->sizeUnder($this->printedCategory());
        [$order, $warehouse] = $this->readyOrder([$size], $headers);
        $item = $order->items()->sole();

        $costBefore = (string) $item->cogs;
        $balanceBefore = $this->balanceOf($warehouse, $size);

        // Act — takes 200 of 300.
        $this->deliver($order, $headers, [$this->key($item) => '200'])->assertOk();

        // Assert — the invoice falls…
        $item->refresh();
        $this->assertSame(UndeliveredDisposition::WrittenOff, $item->undelivered_disposition);
        $this->assertSame('220.00', (string) $item->line_total);

        // …the shelf does not move: these bags carry the customer's artwork and are gone.
        $this->assertSame($balanceBefore, $this->balanceOf($warehouse, $size));

        // …the cost stays whole, because we made all 300.
        $this->assertSame($costBefore, (string) $item->cogs);

        // …and nothing was restocked, which is null rather than zero — the shelf was never
        // opened for these bags.
        $this->assertNull($item->restocked_quantity);

        // …and the loss is named: a third of the line's cost.
        $expected = bcdiv(bcmul($costBefore, '100', 8), '300', 2);
        $this->assertSame($expected, $item->deliveryLoss());
        $this->assertDatabaseHas('production_cost_entries', [
            'order_item_id' => $item->getKey(),
            'cost_type' => 'delivery_loss',
            'quantity' => '100.000',
            'amount' => $expected,
            'rate' => null,
        ]);
    }

    public function test_the_loss_is_reported_and_never_added_to_cogs(): void
    {
        // Arrange
        $headers = $this->clerk();
        $size = $this->sizeUnder($this->printedCategory());
        [$order] = $this->readyOrder([$size], $headers);
        $item = $order->items()->sole();

        $laborBefore = $item->labor_cost;
        $overheadBefore = $item->overhead_cost;
        $cogsBefore = (string) $item->cogs;

        // Act
        $this->deliver($order, $headers, [$this->key($item) => '200'])->assertOk();

        // Assert — the loss entry exists and changed none of the three cost caches. Adding it
        // would charge the undelivered bags twice: once inside the frozen material cost, and
        // once as the loss.
        $item->refresh();
        $this->assertSame($laborBefore, $item->labor_cost);
        $this->assertSame($overheadBefore, $item->overhead_cost);
        $this->assertSame($cogsBefore, (string) $item->cogs);
    }

    public function test_outsourced_goods_are_written_off_rather_than_restocked(): void
    {
        // Arrange — وسيط: a vendor makes it, nothing of ours was ever on a shelf for it.
        // `isPrinted()` answers *false* here, so a disposition derived from it would have tried
        // to put these bags back on a shelf they never occupied.
        $headers = $this->clerk();
        $size = $this->sizeUnder($this->outsourcedCategory());
        [$order] = $this->readyOrder([$size], $headers);
        $item = $order->items()->sole();

        $this->assertFalse($item->isPrinted());

        // Act
        $this->deliver($order, $headers, [$this->key($item) => '200'])->assertOk();

        // Assert
        $item->refresh();
        $this->assertSame(UndeliveredDisposition::WrittenOff, $item->undelivered_disposition);
        $this->assertNull($item->fulfillment_stock_movement_id);
        $this->assertDatabaseMissing('stock_movements', [
            'movement_type' => 'order_reversal',
            'reference_id' => $order->getKey(),
        ]);
    }

    // ───────────────────────────── a mixed order ─────────────────────────────

    public function test_a_mixed_order_restocks_its_plain_line_and_writes_off_its_printed_one(): void
    {
        // Arrange
        $headers = $this->clerk();
        $plain = $this->sizeUnder($this->blankCategory());
        $printed = $this->sizeUnder($this->printedCategory());
        [$order, $warehouse] = $this->readyOrder([$plain, $printed], $headers);

        $plainLine = $order->items()->where('product_id', $plain->product_id)->sole();
        $printedLine = $order->items()->where('product_id', $printed->product_id)->sole();

        $printedBalance = $this->balanceOf($warehouse, $printed);

        // Act — 200 of each taken.
        $this->deliver($order, $headers, [
            $this->key($plainLine) => '200',
            $this->key($printedLine) => '200',
        ])->assertOk();

        // Assert — one order, two dispositions, in one move.
        $this->assertSame(UndeliveredDisposition::Restocked, $plainLine->refresh()->undelivered_disposition);
        $this->assertSame(UndeliveredDisposition::WrittenOff, $printedLine->refresh()->undelivered_disposition);

        // The plain line's goods came back; the printed line's shelf never moved.
        $this->assertSame('800.000', $this->balanceOf($warehouse, $plain));
        $this->assertSame($printedBalance, $this->balanceOf($warehouse, $printed));

        // Only the printed line carries a loss.
        $this->assertNull($plainLine->deliveryLoss());
        $this->assertNotNull($printedLine->deliveryLoss());

        // Both halves of the invoice fell.
        $this->assertSame('440.00', (string) $order->refresh()->items_total); // 2 × 220
    }

    // ───────────────────────────── the ordinary case ─────────────────────────────

    public function test_taking_the_whole_order_records_nothing_at_all(): void
    {
        // Arrange — the form opens pre-filled with the full quantity, so agreeing is one tap and
        // the answer comes back identical. That must behave exactly as delivery did before.
        $headers = $this->clerk();
        $size = $this->sizeUnder($this->blankCategory());
        [$order, $warehouse] = $this->readyOrder([$size], $headers);
        $item = $order->items()->sole();

        $balanceBefore = $this->balanceOf($warehouse, $size);
        $totalBefore = (string) $order->items_total;

        // Act — the pre-filled value, sent back untouched.
        $this->deliver($order, $headers, [$this->key($item) => '300.000'])->assertOk();

        // Assert
        $item->refresh();
        $this->assertNull($item->undelivered_quantity);
        $this->assertNull($item->undelivered_disposition);
        $this->assertSame($balanceBefore, $this->balanceOf($warehouse, $size));
        $this->assertSame($totalBefore, (string) $order->refresh()->items_total);
    }

    public function test_delivering_with_no_fields_at_all_is_unchanged(): void
    {
        // Arrange — every client written before this feature, and every driver without the grant.
        $headers = $this->clerk();
        $size = $this->sizeUnder($this->blankCategory());
        [$order] = $this->readyOrder([$size], $headers);
        $totalBefore = (string) $order->items_total;

        // Act
        $this->deliver($order, $headers)->assertOk();

        // Assert
        $this->assertNull($order->items()->sole()->undelivered_quantity);
        $this->assertSame($totalBefore, (string) $order->refresh()->items_total);
        $this->assertSame(OrderStatus::Delivered, $order->status);
    }

    // ───────────────────────────── the permission ─────────────────────────────

    public function test_the_grant_withholds_the_boxes_and_never_the_move(): void
    {
        // Arrange — somebody who may hand a parcel over but was not granted the new permission.
        $driver = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ManageOrders,
            PermissionName::MoveOrderToReadyToPrint,
            PermissionName::MoveOrderToPrinting,
            PermissionName::MoveOrderToManufacturing,
            PermissionName::MoveOrderToReady,
            PermissionName::DispatchOrders,
            PermissionName::MarkOrdersDelivered,
            PermissionName::ViewInventory,
            PermissionName::ManageInventory,
        );
        $size = $this->sizeUnder($this->blankCategory());
        [$order] = $this->readyOrder([$size], $driver);
        $item = $order->items()->sole();

        $this->withHeaders($driver)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::OfficePickup->value,
        ])->assertOk();

        // Assert — the move is offered and carries no per-line box.
        $transitions = collect($this->withHeaders($driver)
            ->getJson("/api/v1/orders/{$order->id}")
            ->json('data.available_transitions'));

        $delivered = $transitions->firstWhere('status', OrderStatus::Delivered->value);
        $this->assertNotNull($delivered, 'the move itself must still be offered');
        $this->assertNotContains(
            $this->key($item),
            array_column($delivered['fields'] ?? [], 'key'),
        );

        // Act — and posting the key anyway is refused by name rather than silently recorded.
        $this->withHeaders($driver)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Delivered->value,
            'fields' => [$this->key($item) => '200'],
        ])->assertStatus(422);

        $this->assertNull($item->refresh()->undelivered_quantity);
    }

    public function test_a_holder_of_the_grant_is_offered_the_box_pre_filled(): void
    {
        // Arrange
        $headers = $this->clerk();
        $size = $this->sizeUnder($this->blankCategory());
        [$order] = $this->readyOrder([$size], $headers);
        $item = $order->items()->sole();

        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::OfficePickup->value,
        ])->assertOk();

        // Act
        $delivered = collect($this->withHeaders($headers)
            ->getJson("/api/v1/orders/{$order->id}")
            ->json('data.available_transitions'))
            ->firstWhere('status', OrderStatus::Delivered->value);

        // Assert — one box per line, holding the whole billable quantity so agreeing is a tap.
        $box = collect($delivered['fields'])->firstWhere('key', $this->key($item));
        $this->assertNotNull($box);
        $this->assertSame('300', $box['value']);
        // Loose, because JSON gives an integral float back as an int and the assertion is
        // about the ceiling being the whole billable quantity, not about its PHP type.
        $this->assertEquals(300, $box['max']);
        $this->assertFalse($box['required']);
    }

    // ──────────────────────── telling Investment about it ────────────────────────

    public function test_a_restock_announces_that_the_draw_changed(): void
    {
        // Arrange — the investor behind a سادة line was paid سعر السادة the day the bags left
        // the shelf. A hundred of them just came back, credited to *his* cost layers rather than
        // the company's, so leaving that payment standing would have him holding the money and
        // the goods at once — and the next order would buy the same kilo from him again.
        //
        // `PostDealStockPurchases` already knows how to reverse and re-post a redrawn line; what
        // it needs is to be told, and `OrderStockDrawn` is the telling.
        $headers = $this->clerk();
        $size = $this->sizeUnder($this->blankCategory());
        [$order] = $this->readyOrder([$size], $headers);
        $item = $order->items()->sole();

        Event::fake([OrderStockDrawn::class]);

        // Act
        $this->deliver($order, $headers, [$this->key($item) => '200'])->assertOk();

        // Assert
        Event::assertDispatched(
            OrderStockDrawn::class,
            fn (OrderStockDrawn $event): bool => $event->orderId === $order->getKey(),
        );
    }

    public function test_a_write_off_announces_nothing_because_no_stock_moved(): void
    {
        // Arrange — printed bags left the shelf at «جاهزة» and are gone. Nothing is credited
        // back, no line's draw changes, and whoever sold us the plain material keeps the money
        // he was rightly paid for goods that were genuinely consumed.
        $headers = $this->clerk();
        $size = $this->sizeUnder($this->printedCategory());
        [$order] = $this->readyOrder([$size], $headers);
        $item = $order->items()->sole();

        Event::fake([OrderStockDrawn::class]);

        // Act
        $this->deliver($order, $headers, [$this->key($item) => '200'])->assertOk();

        // Assert
        Event::assertNotDispatched(OrderStockDrawn::class);
    }

    // ───────────────────────────── the resources ─────────────────────────────

    public function test_the_order_reports_that_it_was_partially_delivered(): void
    {
        // Arrange
        $headers = $this->clerk();
        $size = $this->sizeUnder($this->printedCategory());
        [$order] = $this->readyOrder([$size], $headers);
        $item = $order->items()->sole();

        // Act
        $this->deliver($order, $headers, [$this->key($item) => '200'])->assertOk();

        // Assert — the chip's fact, and the line's own figures.
        $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.is_partially_delivered', true)
            ->assertJsonPath('data.items.0.undelivered_quantity', '100.000')
            ->assertJsonPath('data.items.0.undelivered_disposition', 'written_off')
            ->assertJsonPath('data.items.0.undelivered_disposition_label', 'خسارة')
            ->assertJsonPath('data.items.0.billable_quantity', '200.000')
            // Printed goods go back to nothing, and the app draws «لم يرجع — خسارة» off this
            // being null rather than off re-reading the disposition beside it.
            ->assertJsonPath('data.items.0.restocked_quantity', null);
    }

    public function test_an_order_delivered_whole_is_not_flagged(): void
    {
        // Arrange
        $headers = $this->clerk();
        $size = $this->sizeUnder($this->blankCategory());
        [$order] = $this->readyOrder([$size], $headers);

        // Act
        $this->deliver($order, $headers)->assertOk();

        // Assert
        $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.is_partially_delivered', false)
            ->assertJsonPath('data.items.0.undelivered_quantity', null)
            ->assertJsonPath('data.items.0.delivery_loss', null);
    }

    // ───────────────────────────── the P&L ─────────────────────────────

    public function test_the_loss_is_reported_beside_the_statement_and_not_subtracted_from_it(): void
    {
        // Arrange
        $headers = $this->clerk();
        $reader = $this->auth(PermissionName::ViewProfitAndLossReport);
        $size = $this->sizeUnder($this->printedCategory());
        [$order] = $this->readyOrder([$size], $headers);
        $item = $order->items()->sole();

        // Act
        $this->deliver($order, $headers, [$this->key($item) => '200'])->assertOk();

        $loss = $item->refresh()->deliveryLoss();
        $cogs = (string) $order->refresh()->total_cogs;

        $report = $this->withHeaders($reader)->getJson(sprintf(
            '/api/v1/reports/profit-loss?from=%s&to=%s',
            now()->subDay()->toDateString(),
            now()->addDay()->toDateString(),
        ))->assertOk();

        // Assert — revenue followed the handover, COGS did not, and the loss is named without
        // being netted off anything.
        $report->assertJsonPath('data.revenue.product', '220.00')
            ->assertJsonPath('data.cost_of_goods_sold.total', $cogs)
            ->assertJsonPath('data.losses.partial_delivery', $loss)
            ->assertJsonPath('data.losses.scrap', '0.00')
            ->assertJsonPath('data.losses.total', $loss)
            ->assertJsonPath(
                'data.gross_profit',
                bcsub('220.00', $cogs, 2),
            );
    }

    private function key(OrderItem $item): string
    {
        return "delivered_{$item->getKey()}";
    }
}
