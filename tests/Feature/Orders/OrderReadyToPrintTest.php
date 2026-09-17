<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Order\Actions\ResolveOrderFlow;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Support\TransitionFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * «جاهزة للطباعة» — the line between the warehouse and the press.
 *
 * **Two departments, and until now no moment marked the handover between them.** «جديدة» and
 * «نواقص» are inventory's — the goods are being found, counted and weighed — while «قيد التصميم»
 * and «قيد الطباعة» belong to printing. An order crossed that line invisibly, so the press had no
 * queue to read and found out an order was ready by somebody noticing.
 *
 * `OrderStatusTest` pins the map. This covers what the status *does*: the two gates on the way in
 * — the goods must be weighed, and nothing may still be missing — and the stock that leaves when
 * an order passes them. **And what happens at «جاهزة» afterwards**, which is the half most likely
 * to be got wrong: the warehouse weighed what it pulled, the press knows what the run actually
 * used, and the shelf is corrected to the second figure in whichever direction it moved.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderReadyToPrintTest extends TestCase
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

    /**
     * Someone who may walk an order the whole way, and shelve stock for it.
     *
     * The inventory pair is here rather than in a second identity because the auth guard holds on
     * to the user it resolved for a test's first request — a second identity would silently be the
     * one making every move afterwards.
     */
    private function foreman(): array
    {
        return $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ManageOrders,
            PermissionName::MoveOrderToReadyToPrint,
            PermissionName::MoveOrderToDesigning,
            PermissionName::MoveOrderToPrinting,
            PermissionName::MoveOrderToReady,
            PermissionName::MoveOrderToShortage,
            PermissionName::CancelOrders,
            PermissionName::ViewInventory,
            PermissionName::ManageInventory,
        );
    }

    /**
     * A size, and the shelf behind it.
     *
     * [$stockUnit] is what the shelf counts in. Kilograms against a piece-priced product is the
     * case that makes the weight box appear — see {@see OrderItem::isStockedInAnotherUnit()}.
     */
    private function sellableSize(?PricingUnit $stockUnit = null, ?ProductCategory $category = null): ProductVariant
    {
        $product = Product::factory()->create([
            'pricing_unit' => PricingUnit::Piece,
            'product_category_id' => $category?->getKey(),
        ]);

        return ProductVariant::factory()->for($product)->create(array_filter([
            'label' => '25*35',
            'stock_item_id' => $stockUnit === null
                ? null
                : StockItem::factory()->unit($stockUnit)->create()->getKey(),
        ]));
    }

    /**
     * A new order of one line, and a warehouse holding [$onShelf] of what it will draw.
     *
     * The balance is created in the **shelf's** own unit, not the selling unit: a kilogram shelf
     * carrying a balance counted in pieces is refused outright by
     * `UnitOfMeasurementMismatch`, which is the whole reason
     * `warehouse_quantity` exists in the first place.
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

    private function show(array $headers, Order $order): TestResponse
    {
        return $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");
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

    private function balanceOf(Warehouse $warehouse, ProductVariant $size): string
    {
        return (string) (WarehouseStock::query()
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_item_id', $size->stock_item_id)
            ->first()?->quantity ?? '0.000');
    }

    // ─────────────────────────── the door into the press ───────────────────────────

    public function test_a_new_order_is_offered_the_handover_and_not_the_press(): void
    {
        // Arrange
        [$order] = $this->order($this->sellableSize());
        $headers = $this->foreman();

        // Act
        $offered = array_column($this->show($headers, $order)->json('data.available_transitions'), 'status');

        // Assert — inventory's own two moves. The press is a department away, and an order
        // reaches it through one door rather than by somebody noticing.
        $this->assertEqualsCanonicalizing(['ready_to_print', 'shortage'], $offered);
    }

    public function test_the_handover_leads_into_either_half_of_the_press(): void
    {
        // Arrange
        [$order, , $warehouse] = $this->order($this->sellableSize());
        $headers = $this->foreman();
        $this->move($headers, $order, OrderStatus::ReadyToPrint, ['warehouse_id' => $warehouse->getKey()])
            ->assertOk();

        // Act
        $offered = array_column($this->show($headers, $order->refresh())->json('data.available_transitions'), 'status');

        // Assert — the artwork queue, the machine, or written off. Which of the first two is the
        // press's decision, not the warehouse's.
        $this->assertEqualsCanonicalizing(['designing', 'printing', 'cancelled'], $offered);
    }

    public function test_the_handover_stamps_when_the_warehouse_finished(): void
    {
        // Arrange
        [$order, , $warehouse] = $this->order($this->sellableSize());
        $headers = $this->foreman();

        // Act
        $this->move($headers, $order, OrderStatus::ReadyToPrint, ['warehouse_id' => $warehouse->getKey()])
            ->assertOk();

        // Assert — «كم تقعد الطلبية بين المخزن والمطبعة؟» is the question the status was added to
        // make answerable, and a column is what makes it a query rather than a walk of the
        // timeline.
        $this->assertNotNull($order->refresh()->ready_to_print_at);
    }

    public function test_an_order_that_never_passed_through_it_has_no_stamp(): void
    {
        // Arrange & Act
        [$order] = $this->order($this->sellableSize());

        // Assert — null is the honest answer for every order taken before the handover existed.
        $this->assertNull($order->ready_to_print_at);
    }

    public function test_the_progress_bar_draws_the_handover_on_the_line(): void
    {
        // Arrange
        [$order] = $this->order($this->sellableSize());
        $headers = $this->foreman();

        // Act
        $steps = array_column($this->show($headers, $order)->json('data.progress.steps'), 'status');

        // Assert — every printed order passes through it, so it is a step on the road and not a
        // detour beside it.
        $this->assertSame([
            'new', 'ready_to_print', 'designing', 'printing',
            'ready', 'out_for_delivery', 'delivered', 'settled',
        ], $steps);
    }

    // ──────────────────────── the first gate: the goods are weighed ────────────────────────

    public function test_the_handover_will_not_happen_without_a_warehouse(): void
    {
        // Arrange
        [$order] = $this->order($this->sellableSize());
        $headers = $this->foreman();

        // Act
        $response = $this->move($headers, $order, OrderStatus::ReadyToPrint);

        // Assert — the stock leaves here, so somewhere has to give it up.
        $response->assertStatus(422)->assertJsonValidationErrors('fields.warehouse_id');
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    public function test_a_line_stocked_in_another_unit_must_be_weighed_before_it_is_handed_over(): void
    {
        // Arrange — 300 pieces sold, off a shelf counted in kilograms. No factor converts one to
        // the other, so nobody but the person at the scale can answer.
        $size = $this->sellableSize(PricingUnit::Kilogram);
        [$order, $item, $warehouse] = $this->order($size);
        $headers = $this->foreman();

        // Act — the warehouse named, the weight left out.
        $refused = $this->move($headers, $order, OrderStatus::ReadyToPrint, [
            'warehouse_id' => $warehouse->getKey(),
        ]);

        $accepted = $this->move($headers, $order->refresh(), OrderStatus::ReadyToPrint, [
            'warehouse_id' => $warehouse->getKey(),
            TransitionFields::stockQuantityKey($item) => '3.5',
        ]);

        // Assert — this is the gate the whole status was asked for: the goods are weighed before
        // the press is told they are ready.
        $refused->assertStatus(422)
            ->assertJsonValidationErrors('fields.'.TransitionFields::stockQuantityKey($item));

        $accepted->assertOk()->assertJsonPath('data.status', 'ready_to_print');
        $this->assertSame('3.500', (string) $item->refresh()->warehouse_quantity);
    }

    public function test_an_order_whose_units_agree_is_asked_for_no_weight(): void
    {
        // Arrange — sold by the piece off a shelf counted in pieces.
        [$order, , $warehouse] = $this->order($this->sellableSize(PricingUnit::Piece));
        $headers = $this->foreman();

        // Act
        $fields = array_column(
            $this->transition($this->show($headers, $order), OrderStatus::ReadyToPrint)['fields'],
            'key',
        );
        $response = $this->move($headers, $order, OrderStatus::ReadyToPrint, [
            'warehouse_id' => $warehouse->getKey(),
        ]);

        // Assert — **and this is a consequence worth stating outright.** The weight is asked only
        // where nobody could work it out; what was sold is what leaves otherwise. So an order like
        // this crosses the handover with a warehouse and nothing else — there is no weight to
        // demand of it. A box asking a foreman to retype a number the order already holds could
        // only ever introduce a difference between the two.
        $this->assertNotContains(TransitionFields::stockQuantityKey(new OrderItem), $fields);
        $this->assertSame(['warehouse_id', 'reason'], $fields);
        $response->assertOk();
    }

    // ─────────────────── the second gate: nothing is still missing ───────────────────

    public function test_an_order_still_short_is_not_handed_to_the_press(): void
    {
        // Arrange — parked on a shortage, and nothing has arrived.
        $size = $this->sellableSize();
        [$order, $item, $warehouse] = $this->order($size);
        $headers = $this->foreman();

        $this->move($headers, $order, OrderStatus::Shortage, ["shortage_{$item->id}" => '100'])
            ->assertOk();

        // Act — zero typed over the prefill: none of it turned up.
        $response = $this->move($headers, $order->refresh(), OrderStatus::ReadyToPrint, [
            'warehouse_id' => $warehouse->getKey(),
            "received_{$item->id}" => '0',
        ]);

        // Assert — «جاهزة للطباعة» promises another department a complete order. The message names
        // the size, because the person being refused is standing at the shelves.
        $response->assertStatus(422);
        $this->assertStringContainsString('25*35', $response->json('message'));
        $this->assertSame(OrderStatus::Shortage, $order->fresh()->status);
    }

    public function test_a_shortage_that_arrived_in_full_passes_the_gate(): void
    {
        // Arrange
        $size = $this->sellableSize();
        [$order, $item, $warehouse] = $this->order($size);
        $headers = $this->foreman();

        $this->move($headers, $order, OrderStatus::Shortage, ["shortage_{$item->id}" => '100'])
            ->assertOk();

        // Act — the whole hundred came in, on the same move that hands the order over.
        $response = $this->move($headers, $order->refresh(), OrderStatus::ReadyToPrint, [
            'warehouse_id' => $warehouse->getKey(),
            "received_{$item->id}" => '100',
        ]);

        // Assert — the delivery is counted in *before* the gate judges the order, so a shortage
        // that has genuinely been resolved clears itself and passes on one move rather than two.
        $response->assertOk()->assertJsonPath('data.status', 'ready_to_print');
        $this->assertNull($item->refresh()->shortage_quantity);
    }

    // ──────────────────────────── stock leaves at the handover ────────────────────────────

    public function test_the_stock_leaves_when_the_warehouse_hands_the_order_over(): void
    {
        // Arrange
        $size = $this->sellableSize();
        [$order, , $warehouse] = $this->order($size, '300');
        $headers = $this->foreman();

        // Act
        $this->move($headers, $order, OrderStatus::ReadyToPrint, ['warehouse_id' => $warehouse->getKey()])
            ->assertOk();

        // Assert — the shelf drops here, not three statuses later. That is the point of naming a
        // warehouse at the handover: the goods physically leave when the warehouse lets them go.
        $this->assertSame('700.000', $this->balanceOf($warehouse, $size));

        $order->refresh();
        $this->assertNotNull($order->stock_deducted_at);
        $this->assertSame($warehouse->getKey(), $order->fulfillment_warehouse_id);
    }

    public function test_reaching_the_press_and_the_shelf_does_not_deduct_again(): void
    {
        // Arrange
        $size = $this->sellableSize();
        [$order, , $warehouse] = $this->order($size, '300');
        $headers = $this->foreman();
        $this->move($headers, $order, OrderStatus::ReadyToPrint, ['warehouse_id' => $warehouse->getKey()])
            ->assertOk();

        // Act
        $this->move($headers, $order->refresh(), OrderStatus::Printing)->assertOk();
        $this->move($headers, $order->refresh(), OrderStatus::Ready)->assertOk();

        // Assert — `stock_deducted_at` is what makes the deduction once-per-order however many
        // statuses could in principle perform it.
        $this->assertSame('700.000', $this->balanceOf($warehouse, $size));
        $this->assertSame(1, $this->fulfillmentCount($order));
    }

    private function fulfillmentCount(Order $order): int
    {
        return StockMovement::query()
            ->where('reference_id', $order->getKey())
            ->where('movement_type', 'order_fulfillment')
            ->count();
    }

    // ─────────────── the correction at «جاهزة»: the press knows better ───────────────

    /**
     * @return array{0: Order, 1: OrderItem, 2: Warehouse, 3: ProductVariant, 4: array<string, string>}
     */
    private function weighedAtHandover(string $weight): array
    {
        $size = $this->sellableSize(PricingUnit::Kilogram);
        [$order, $item, $warehouse] = $this->order($size, '300');
        $headers = $this->foreman();

        $this->move($headers, $order, OrderStatus::ReadyToPrint, [
            'warehouse_id' => $warehouse->getKey(),
            TransitionFields::stockQuantityKey($item) => $weight,
        ])->assertOk();

        $this->move($headers, $order->refresh(), OrderStatus::Printing)->assertOk();

        return [$order->refresh(), $item->refresh(), $warehouse, $size, $headers];
    }

    public function test_the_shelf_is_not_asked_for_again_once_the_stock_has_gone(): void
    {
        // Arrange
        [$order, , , , $headers] = $this->weighedAtHandover('3.5');

        // Act
        $fields = array_column(
            $this->transition($this->show($headers, $order), OrderStatus::Ready)['fields'],
            'key',
        );

        // Assert — a correction goes back to the shelf the goods came off, which the order
        // already records. A second picker here could only send the difference somewhere else and
        // leave two shelves wrong instead of one.
        $this->assertNotContains('warehouse_id', $fields);
    }

    public function test_using_more_than_was_pulled_takes_the_difference_out(): void
    {
        // Arrange — 3.5 pulled off the shelf at the handover.
        [$order, $item, $warehouse, $size, $headers] = $this->weighedAtHandover('3.5');
        $this->assertSame('996.500', $this->balanceOf($warehouse, $size));

        // Act — the run actually ate 3.8.
        $this->move($headers, $order, OrderStatus::Ready, [
            TransitionFields::stockQuantityKey($item) => '3.8',
        ])->assertOk();

        // Assert — the shelf ends where the true figure puts it, not where the estimate did.
        $this->assertSame('996.200', $this->balanceOf($warehouse, $size));
        $this->assertSame('3.800', (string) $item->refresh()->warehouse_quantity);
    }

    public function test_using_less_than_was_pulled_puts_the_difference_back(): void
    {
        // Arrange
        [$order, $item, $warehouse, $size, $headers] = $this->weighedAtHandover('3.5');

        // Act — only 3.2 was used.
        $this->move($headers, $order, OrderStatus::Ready, [
            TransitionFields::stockQuantityKey($item) => '3.2',
        ])->assertOk();

        // Assert — the same one path as the case above, in the other direction: the original draw
        // is credited back in full and the corrected one taken, so no partial-credit arithmetic
        // exists anywhere to get the sign wrong.
        $this->assertSame('996.800', $this->balanceOf($warehouse, $size));
        $this->assertSame('3.200', (string) $item->refresh()->warehouse_quantity);
    }

    public function test_confirming_the_same_figure_writes_nothing_to_the_ledger(): void
    {
        // Arrange
        [$order, $item, $warehouse, $size, $headers] = $this->weighedAtHandover('3.5');
        $before = $this->fulfillmentCount($order);

        // Act — the box opens holding 3.500 and the foreman leaves it alone, which is an answer
        // rather than a silence. Sent back in the trailing-zero form the form prints it in.
        $this->move($headers, $order, OrderStatus::Ready, [
            TransitionFields::stockQuantityKey($item) => '3.500',
        ])->assertOk();

        // Assert — the common case stays silent. A ledger that recorded a reversal and a re-draw
        // every time somebody confirmed a number would bury the corrections that matter.
        $this->assertSame($before, $this->fulfillmentCount($order));
        $this->assertSame('996.500', $this->balanceOf($warehouse, $size));
    }

    public function test_a_correction_the_shelf_cannot_cover_is_refused(): void
    {
        // Arrange — a shelf holding four kilograms and nothing more.
        $size = $this->sellableSize(PricingUnit::Kilogram);
        [$order, $item, $warehouse] = $this->order($size, '300', onShelf: '4');
        $headers = $this->foreman();

        $this->move($headers, $order, OrderStatus::ReadyToPrint, [
            'warehouse_id' => $warehouse->getKey(),
            TransitionFields::stockQuantityKey($item) => '3.5',
        ])->assertOk();
        $this->move($headers, $order->refresh(), OrderStatus::Printing)->assertOk();

        // Act — claiming 100 were used when the shelf never held that much.
        $response = $this->move($headers, $order->refresh(), OrderStatus::Ready, [
            TransitionFields::stockQuantityKey($item) => '100',
        ]);

        // Assert — the re-draw is guarded exactly as the first draw was, so a correction cannot
        // drive a balance negative. Nothing of the refused move is kept.
        $response->assertStatus(422);
        $this->assertSame('0.500', $this->balanceOf($warehouse, $size));
        $this->assertSame('3.500', (string) $item->refresh()->warehouse_quantity);
        $this->assertSame(OrderStatus::Printing, $order->fresh()->status);
    }

    public function test_cancelling_after_a_correction_credits_back_the_corrected_figure(): void
    {
        // Arrange — pulled 3.5, actually used 3.2.
        [$order, $item, $warehouse, $size, $headers] = $this->weighedAtHandover('3.5');
        $this->move($headers, $order, OrderStatus::Ready, [
            TransitionFields::stockQuantityKey($item) => '3.2',
        ])->assertOk();

        // Act
        $this->move($headers, $order->refresh(), OrderStatus::Cancelled)
            ->assertStatus(422); // a cancellation owes a reason

        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Cancelled->value,
            'reason' => 'العميل ألغى الطلب',
        ])->assertOk();

        // Assert — **the regression test for keeping one movement id per line.** The reversal
        // credits back what the order actually took, not what it first pulled: 3.2 returns and
        // the shelf lands back where it started. A delta movement at «جاهزة» would have left
        // `fulfillment_stock_movement_id` naming one of two draws, and this figure would be wrong.
        $this->assertSame('1000.000', $this->balanceOf($warehouse, $size));
    }

    // ──────────────────────── goods that never see the press ────────────────────────

    public function test_an_order_of_ready_made_goods_never_visits_the_handover(): void
    {
        // Arrange — every line «سادة», so the order has nothing to design and nothing to print.
        $category = ProductCategory::factory()->skipsProduction()->create(['name' => 'سادة']);
        [$order, , $warehouse] = $this->order($this->sellableSize(PricingUnit::Piece, $category));
        app(ResolveOrderFlow::class)($order->load('items'));
        $headers = $this->foreman();

        // Act
        $offered = array_column(
            $this->show($headers, $order->refresh())->json('data.available_transitions'),
            'status',
        );
        $ready = $this->transition($this->show($headers, $order), OrderStatus::Ready);

        // Assert — «جاهزة للطباعة» is a handover to a department this order never reaches, so
        // putting it on that road would be a status naming work nobody does. Its «جاهزة» is
        // therefore still the first and only deduction, and asks for the warehouse in full.
        $this->assertEqualsCanonicalizing(['ready', 'shortage'], $offered);
        $this->assertContains('warehouse_id', array_column($ready['fields'], 'key'));

        $this->move($headers, $order, OrderStatus::Ready, ['warehouse_id' => $warehouse->getKey()])
            ->assertOk();
        $this->assertNotNull($order->refresh()->stock_deducted_at);
    }

    public function test_a_blank_order_still_draws_the_shorter_bar(): void
    {
        // Arrange
        $category = ProductCategory::factory()->skipsProduction()->create(['name' => 'سادة']);
        [$order] = $this->order($this->sellableSize(PricingUnit::Piece, $category));
        app(ResolveOrderFlow::class)($order->load('items'));
        $headers = $this->foreman();

        // Act
        $steps = array_column(
            $this->show($headers, $order->refresh())->json('data.progress.steps'),
            'status',
        );

        // Assert — five steps, and the handover is not among them: it counts as production, so it
        // leaves the line with the two statuses it stands in front of.
        $this->assertSame(['new', 'ready', 'out_for_delivery', 'delivered', 'settled'], $steps);
    }
}
