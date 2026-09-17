<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Enums\PricingMode;
use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Enums\ProductionMode;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\City;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\ResolveOrderFlow;
use App\Domain\Order\Enums\OrderFlow;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The short road: an order of goods that are already made goes جديدة → جاهزة.
 *
 * **A كيس سادة is not printed, and until this existed it had to pretend it was.** Every order in
 * the shop walked the one road, so a plain bag pulled off a shelf and counted had to be walked
 * through «قيد التصميم» and «قيد الطباعة» — two statuses naming work nobody did — before it could
 * be called ready.
 *
 * `OrderStatusTest` pins the two maps as maps. This file covers the half a map cannot state: how
 * an order *acquires* its road, that the road is read off the lines and never off a request, that
 * it is a snapshot rather than a live lookup, and — the thing most likely to be quietly broken —
 * that skipping the press skips **nothing** about fulfilment. Entering «جاهزة» still names a
 * warehouse, still asks what comes off the shelf, and still deducts it.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderProductionFlowTest extends TestCase
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

    /** Someone allowed to move an order anywhere either map allows. */
    private function foreman(): array
    {
        return $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ManageOrders,
            PermissionName::ManageOrderDesigns,
            PermissionName::MoveOrderToReadyToPrint,
            PermissionName::MoveOrderToDesigning,
            PermissionName::MoveOrderToPrinting,
            PermissionName::MoveOrderToReady,
            PermissionName::MoveOrderToShortage,
            PermissionName::DispatchOrders,
            PermissionName::CancelOrders,
            // Shelving what the order will draw on, so the arrivals in {@see stock()} and the
            // move that consumes them are made by one identity — the same pair
            // `ProductionCostTest::foreman()` carries, and for the same reason.
            PermissionName::ViewInventory,
            PermissionName::ManageInventory,
        );
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::query()->firstOr(fn () => Warehouse::factory()->create());
    }

    /** «سادة» — goods that arrive on the shelf already made. */
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

    /**
     * A sellable size filed under a given heading.
     *
     * [$stockUnit] is what the *shelf* counts in. Left null the variant factory gives the size a
     * shelf of its own counted in pieces, which matches the selling unit and so asks nothing
     * extra on the way into «جاهزة». Passing kilograms against a piece-priced product is the case
     * that makes the weight box appear — see {@see OrderItem::isStockedInAnotherUnit()}.
     */
    private function sizeUnder(
        ?ProductCategory $category,
        PricingUnit $sold = PricingUnit::Piece,
        ?PricingUnit $stockUnit = null,
    ): ProductVariant {
        $product = Product::factory()->create([
            'product_category_id' => $category?->getKey(),
            'pricing_unit' => $sold,
            'pricing_mode' => PricingMode::Tiered,
            'min_order_quantity' => '100',
        ]);

        $variant = ProductVariant::factory()->for($product)->create(
            // Every size here is stocked: reaching «جاهزة» deducts, and `DeductOrderStock`
            // refuses a variant with no shelf behind it whichever road the order took.
            array_filter([
                'label' => '25*35',
                'stock_item_id' => $stockUnit === null
                    ? null
                    : StockItem::factory()->unit($stockUnit)->create()->getKey(),
            ]),
        );

        ProductPriceTier::factory()->create([
            'product_variant_id' => $variant->getKey(),
            'min_quantity' => '100',
            'unit_price' => '1.100',
        ]);

        return $variant;
    }

    /**
     * Puts enough of every size on a warehouse's shelves for an order to be fulfilled from it.
     *
     * Through the arrivals endpoint rather than a `WarehouseStock` factory, the way
     * `ProductionCostTest` does it: stock that arrived has cost layers behind it, and the
     * deduction reads them.
     *
     * Takes the caller's own headers rather than minting an identity of its own: the auth guard
     * holds on to the user it resolved for the first request of a test, so a second identity here
     * would silently be the one making the move afterwards.
     *
     * @param  list<ProductVariant>  $sizes
     * @param  array<string, string>  $headers
     */
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

    /**
     * An order standing in [$status] with one line per given size, its road already resolved.
     *
     * Built from factories rather than through the endpoint because most of these tests are about
     * what the *machine* does with a road. The one test that proves `CreateOrder` stamps it goes
     * through the API instead.
     *
     * @param  list<ProductVariant>  $sizes
     */
    private function orderOf(array $sizes, OrderStatus $status = OrderStatus::New): Order
    {
        $order = Order::factory()->create();

        foreach ($sizes as $size) {
            OrderItem::factory()->for($order)->create([
                'product_id' => $size->product_id,
                'product_variant_id' => $size->getKey(),
                'variant_label' => $size->label,
                'quantity' => '300',
                'pricing_unit' => $size->product->pricing_unit,
            ]);
        }

        // **Stamped while the order is «جديدة», then moved — the sequence a real order lives.**
        // The flow is read at intake and never again (see {@see ResolveOrderFlow}), so an order
        // built directly into «نواقص» and stamped afterwards would be asserting against a
        // fixture the domain can never produce. Put through the same action intake uses, rather
        // than given a flow by hand, for the same reason.
        app(ResolveOrderFlow::class)($order->load('items'));

        if ($status !== OrderStatus::New) {
            $order->forceFill(['status' => $status])->save();
        }

        return $order->refresh();
    }

    private function show(array $headers, Order $order): TestResponse
    {
        return $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");
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

    /**
     * @return array<string, mixed>|null
     */
    private function transition(TestResponse $response, OrderStatus $target): ?array
    {
        foreach ($response->json('data.available_transitions') as $transition) {
            if ($transition['status'] === $target->value) {
                return $transition;
            }
        }

        return null;
    }

    // ─────────────────────────── which road an order acquires ───────────────────────────

    public function test_an_order_of_only_unprinted_goods_takes_the_short_road(): void
    {
        // Arrange
        $blank = $this->blankCategory();

        // Act
        $order = $this->orderOf([$this->sizeUnder($blank), $this->sizeUnder($blank)]);

        // Assert — two lines, both «سادة»: nothing to design and nothing to print.
        $this->assertSame(OrderFlow::NoProduction, $order->production_flow);
    }

    public function test_one_printed_line_puts_the_whole_order_back_on_the_press(): void
    {
        // Arrange — the mixed order, and the decision that matters most in this file.
        $blank = $this->blankCategory();
        $printed = $this->printedCategory();

        // Act
        $order = $this->orderOf([$this->sizeUnder($blank), $this->sizeUnder($printed)]);

        // Assert — «any line» reads as the generous rule and is the one that loses a print job:
        // an order that skipped «قيد الطباعة» would claim work had been done to a line nobody
        // had touched.
        $this->assertSame(OrderFlow::Standard, $order->production_flow);
    }

    public function test_an_order_of_only_outsourced_goods_takes_the_vendor_road(): void
    {
        // Arrange — 50 كرت بزنس filed under «وسيط», the worked example from the requirement.
        $category = $this->outsourcedCategory();

        // Act
        $order = $this->orderOf([$this->sizeUnder($category)]);

        // Assert — the road is read off the lines and stamped, exactly as the other two are.
        $this->assertSame(OrderFlow::Outsourced, $order->production_flow);
    }

    public function test_one_line_we_make_ourselves_keeps_the_whole_order_off_the_vendor_road(): void
    {
        // Arrange — a وسيط line beside a printed one.
        $outsourced = $this->outsourcedCategory();
        $printed = $this->printedCategory();

        // Act
        $order = $this->orderOf([$this->sizeUnder($outsourced), $this->sizeUnder($printed)]);

        // Assert — «every line, or none», the same rule the short road already obeys. An order
        // that took the vendor road with a printed line on it would skip «قيد الطباعة» for work
        // our own press still has to do.
        $this->assertSame(OrderFlow::Standard, $order->production_flow);
    }

    public function test_two_kinds_of_shortcut_on_one_order_cancel_each_other_out(): void
    {
        // Arrange — a وسيط line and a سادة line: each *alone* would shorten the road, and they
        // shorten it in different directions.
        $outsourced = $this->outsourcedCategory();
        $blank = $this->blankCategory();

        // Act
        $order = $this->orderOf([$this->sizeUnder($outsourced), $this->sizeUnder($blank)]);

        // Assert — unanimity, not «some line skips something». The two roads disagree about
        // whether stock leaves and about which statuses exist, and an order cannot walk half of
        // each; the standard road is the one that asks more of the shop, so it is the fallback.
        $this->assertSame(OrderFlow::Standard, $order->production_flow);
    }

    public function test_a_heading_inherits_the_answer_of_the_heading_above_it(): void
    {
        // Arrange — «سادة» marked, and «سادة ورقية» added underneath it later without anybody
        // thinking to tick the box a second time.
        $blank = $this->blankCategory();
        $child = ProductCategory::factory()->create([
            'name' => 'سادة ورقية',
            'parent_id' => $blank->getKey(),
        ]);

        // Act
        $order = $this->orderOf([$this->sizeUnder($child)]);

        // Assert — a product is filed under a leaf, so without the inheritance the flag would be
        // silently dropped by every product that moved down into the new subheading.
        $this->assertSame(ProductionMode::InHouse, $child->production_mode);
        $this->assertSame(ProductionMode::None, $child->productionMode());
        $this->assertSame(OrderFlow::NoProduction, $order->production_flow);
    }

    public function test_a_product_with_no_heading_is_treated_as_production_work(): void
    {
        // Arrange — `product_category_id` is nullable for the products recorded before categories
        // existed; see PRODUCT-CATEGORIES.md.

        // Act
        $order = $this->orderOf([$this->sizeUnder(null)]);

        // Assert — the unknown case takes the road that asks more of the shop, never the one that
        // asks less. A line that cannot prove it skips the press does not get to.
        $this->assertSame(OrderFlow::Standard, $order->production_flow);
    }

    public function test_taking_an_order_stamps_the_road_it_walks(): void
    {
        // Arrange — through the endpoint, so `CreateOrder` is what does the stamping.
        $size = $this->sizeUnder($this->blankCategory());
        $headers = $this->auth(PermissionName::ViewOrders, PermissionName::ManageOrders);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', [
            'customer_id' => Customer::factory()->create()->getKey(),
            'city_id' => City::factory()->create(['delivery_price' => '20.00'])->getKey(),
            'address_details' => 'شارع الجمهورية',
            'items' => [[
                'product_id' => $size->product_id,
                'product_variant_id' => $size->getKey(),
                'quantity' => '300',
            ]],
        ]);

        // Assert — read off the lines by the server, never taken from the payload.
        $response->assertCreated()
            ->assertJsonPath('data.production_flow', 'no_production')
            ->assertJsonPath('data.production_flow_label', 'بلا تصميم وطباعة');
    }

    // ──────────────────────────── what the machine offers ────────────────────────────

    public function test_a_plain_order_is_offered_the_shelf_from_the_moment_it_is_taken(): void
    {
        // Arrange
        $order = $this->orderOf([$this->sizeUnder($this->blankCategory())]);
        $headers = $this->foreman();

        // Act
        $offered = array_column($this->show($headers, $order)->json('data.available_transitions'), 'status');

        // Assert — «جاهزة» and «نواقص», and neither of the two statuses that name work. The
        // shortage stays because the stock for a plain bag is exactly the thing that turns out
        // not to be there.
        $this->assertEqualsCanonicalizing(['ready', 'shortage'], $offered);
    }

    public function test_a_plain_order_draws_a_shorter_progress_bar(): void
    {
        // Arrange
        $plain = $this->orderOf([$this->sizeUnder($this->blankCategory())]);
        $printed = $this->orderOf([$this->sizeUnder($this->printedCategory())]);
        $headers = $this->foreman();

        // Act
        $plainSteps = array_column($this->show($headers, $plain)->json('data.progress.steps'), 'status');
        $printedSteps = array_column($this->show($headers, $printed)->json('data.progress.steps'), 'status');

        // Assert — five steps rather than seven. Leaving the two drawn-but-never-reached would
        // have the bar claim a plain order is two sevenths of the way through at the moment it is
        // standing on the shelf ready to go.
        $this->assertSame(['new', 'ready', 'out_for_delivery', 'delivered', 'settled'], $plainSteps);
        $this->assertSame([
            'new', 'ready_to_print', 'designing', 'printing',
            'ready', 'out_for_delivery', 'delivered', 'settled',
        ], $printedSteps);
    }

    public function test_a_plain_order_parked_short_rejoins_at_the_shelf(): void
    {
        // Arrange — the arm that is easy to forget: «نواقص» is reachable on the short road, so it
        // needs a way back onto it.
        $order = $this->orderOf([$this->sizeUnder($this->blankCategory())], OrderStatus::Shortage);
        $headers = $this->foreman();

        // Act
        $offered = array_column($this->show($headers, $order)->json('data.available_transitions'), 'status');

        // Assert — «جاهزة» rather than the design queue this order was never going to visit, and
        // never an empty list: a shortage with no way out is an order parked forever.
        $this->assertEqualsCanonicalizing(['ready', 'cancelled'], $offered);
    }

    // ───────────────────────────────── making the move ─────────────────────────────────

    public function test_a_plain_order_goes_straight_to_the_shelf(): void
    {
        // Arrange
        $size = $this->sizeUnder($this->blankCategory());
        $order = $this->orderOf([$size]);
        $headers = $this->foreman();
        $this->stock([$size], $this->warehouse(), $headers);

        // Act
        $response = $this->move($headers, $order, OrderStatus::Ready, [
            'warehouse_id' => $this->warehouse()->getKey(),
        ]);

        // Assert — the whole point, end to end.
        $response->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('message', 'تم نقل الطلبية إلى «جاهزة»');
    }

    public function test_a_mixed_order_is_refused_the_short_road(): void
    {
        // Arrange
        $order = $this->orderOf([
            $this->sizeUnder($this->blankCategory()),
            $this->sizeUnder($this->printedCategory()),
        ]);
        $headers = $this->foreman();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Ready, [
            'warehouse_id' => $this->warehouse()->getKey(),
        ]);

        // Assert — the map is enforced against *this order's* road, not against a general one.
        // `ChangeOrderStatus` passing the flow is what stops the short road existing on screen
        // and nowhere else.
        $response->assertStatus(422);
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    // ──────────────── skipping the press is not skipping fulfilment ────────────────

    public function test_the_short_road_asks_for_the_warehouse_and_the_weight_exactly_as_the_press_does(): void
    {
        // Arrange — the same shape of goods twice, filed under two headings: sold by the piece,
        // stocked by the kilo, which is the case that makes the weight box appear. The seeder
        // calls «سادة» «تُباع غالباً بالوزن», so this is the *common* case on the short road.
        $plain = $this->orderOf([
            $this->sizeUnder($this->blankCategory(), PricingUnit::Piece, PricingUnit::Kilogram),
        ]);
        $printed = $this->orderOf(
            [$this->sizeUnder($this->printedCategory(), PricingUnit::Piece, PricingUnit::Kilogram)],
            OrderStatus::Printing,
        );
        $headers = $this->foreman();

        // Act
        $onPlain = $this->transition($this->show($headers, $plain), OrderStatus::Ready)['fields'];
        $onPrinted = $this->transition($this->show($headers, $printed), OrderStatus::Ready)['fields'];

        // Assert — the same boxes, the same requiredness, in the same order. `TransitionFields`
        // keys everything off the *target* status and never off where the order came from, so
        // this is a fact the code cannot drift from — but it is the fact somebody adding a third
        // road would break first.
        //
        // The per-line key carries its own order item's id, which two different orders cannot
        // share, so the comparison is of the *shape*: the id is the one part that is allowed to
        // differ, and blanking it is what leaves everything else being asserted.
        $shape = static fn (array $fields): array => array_map(
            static fn (array $field): array => [
                'key' => preg_replace('/_\d+$/', '_#', (string) $field['key']),
                'type' => $field['type'],
                'required' => $field['required'],
            ],
            $fields,
        );

        $this->assertSame($shape($onPrinted), $shape($onPlain));

        // And spelt out, so a change that made both roads wrong in the same way still fails:
        // the warehouse, then the weight for the one line that needs one, then the note every
        // move carries.
        $this->assertSame(
            ['warehouse_id', 'warehouse_quantity_#', 'reason'],
            array_column($shape($onPlain), 'key'),
        );
        $this->assertTrue($onPlain[0]['required'], 'the warehouse must be named');
        $this->assertTrue($onPlain[1]['required'], 'the weight must be measured');
        $this->assertStringContainsString(PricingUnit::Kilogram->label(), $onPlain[1]['label']);
    }

    public function test_the_short_road_will_not_reach_the_shelf_without_a_warehouse(): void
    {
        // Arrange
        $order = $this->orderOf([$this->sizeUnder($this->blankCategory())]);
        $headers = $this->foreman();

        // Act — no fields at all.
        $response = $this->move($headers, $order, OrderStatus::Ready);

        // Assert — `ChangeOrderStatusRequest` builds its rules from the same `TransitionFields`
        // list the form was drawn from, so the requirement reaches the short road too.
        $response->assertStatus(422)->assertJsonValidationErrors('fields.warehouse_id');
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    public function test_reaching_the_shelf_without_the_press_still_takes_the_stock(): void
    {
        // Arrange
        $size = $this->sizeUnder($this->blankCategory());
        $order = $this->orderOf([$size]);
        $warehouse = $this->warehouse();
        $headers = $this->foreman();
        $this->stock([$size], $warehouse, $headers);

        // Act
        $this->move($headers, $order, OrderStatus::Ready, ['warehouse_id' => $warehouse->getKey()])
            ->assertOk();

        // Assert — arriving from «جديدة» rather than from «قيد الطباعة» changes nothing about what
        // leaves the building: `$deductStock` is keyed on the target alone.
        $order->refresh();
        $this->assertNotNull($order->stock_deducted_at);
        $this->assertSame($warehouse->getKey(), $order->fulfillment_warehouse_id);

        // The movement itself, and the cost it drew — 300 pieces at the 4.00 they arrived at.
        $this->assertDatabaseHas('stock_movements', [
            'stock_item_id' => $size->stock_item_id,
            'from_warehouse_id' => $warehouse->getKey(),
            'reference_id' => $order->getKey(),
        ]);
        $this->assertSame('1200.00', (string) $order->items()->sole()->material_cost);
    }

    // ───────────────────────── the road is a snapshot ─────────────────────────

    public function test_flipping_a_heading_does_not_re_route_an_order_already_taken(): void
    {
        // Arrange — an ordinary printed order, taken and sitting in «جديدة».
        $category = $this->printedCategory();
        $order = $this->orderOf([$this->sizeUnder($category)]);
        $headers = $this->foreman();

        // Act — somebody decides this heading needs no press after all.
        $category->update(['production_mode' => ProductionMode::None]);

        // Assert — the order keeps the road it was taken under. Re-reading live would have an
        // order already at the press lose «قيد الطباعة» from its own progress bar and start
        // drawing itself as a detour from the status it is standing in.
        $this->assertSame(OrderFlow::Standard, $order->fresh()->production_flow);
        $this->assertContains(
            // The road into the press, which is what a printed order in «جديدة» is offered.
            'ready_to_print',
            array_column($this->show($headers, $order)->json('data.available_transitions'), 'status'),
        );
    }

    public function test_editing_the_lines_of_a_new_order_re_reads_its_road(): void
    {
        // Arrange — taken as a printed job, then the customer changes their mind before anybody
        // has started it.
        $order = $this->orderOf([$this->sizeUnder($this->printedCategory())]);
        $plain = $this->sizeUnder($this->blankCategory());
        $headers = $this->auth(PermissionName::ViewOrders, PermissionName::ManageOrders);

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->id}", [
            'customer_id' => $order->customer_id,
            'city_id' => $order->city_id,
            'address_details' => $order->address_details,
            'items' => [[
                'product_id' => $plain->product_id,
                'product_variant_id' => $plain->getKey(),
                'quantity' => '300',
            ]],
        ]);

        // Assert — nothing has been done to this order yet, so which road it walks is still an
        // open question and the new lines answer it.
        $response->assertOk()->assertJsonPath('data.production_flow', 'no_production');
    }

    public function test_editing_the_lines_of_an_order_at_the_press_does_not_move_it_off_the_press(): void
    {
        // Arrange — the case the «جديدة»-only guard exists for. Lines stay editable while the
        // press runs, on purpose: a quantity gets corrected mid-run.
        $order = $this->orderOf([$this->sizeUnder($this->printedCategory())], OrderStatus::Printing);
        $plain = $this->sizeUnder($this->blankCategory());
        $headers = $this->auth(PermissionName::ViewOrders, PermissionName::ManageOrders);

        // Act — swap the last printed line out of an order that is already being printed.
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->id}", [
            'customer_id' => $order->customer_id,
            'city_id' => $order->city_id,
            'address_details' => $order->address_details,
            'items' => [[
                'product_id' => $plain->product_id,
                'product_variant_id' => $plain->getKey(),
                'quantity' => '300',
            ]],
        ]);

        // Assert — it stays on the road it is standing on. Re-reading here would put the order on
        // a main line with no «قيد الطباعة» on it while it is *in* «قيد الطباعة», and
        // `mainLinePosition()` would answer null — an ordinary order drawing itself as a detour.
        $response->assertOk()->assertJsonPath('data.production_flow', 'standard');
        $this->assertFalse($response->json('data.progress.is_detour'));
    }
}
