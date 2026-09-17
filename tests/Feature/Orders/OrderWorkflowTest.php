<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerDesign;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\ShippingCompany;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Order\Enums\DesignSource;
use App\Domain\Order\Enums\OrderDesignStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderDesign;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Moving an order through the machine, and the artwork conversation that gates printing.
 *
 * `OrderStatusTest` pins the map itself; this covers what the endpoint does with it — the
 * permission each move costs, the reason a cancellation owes, the timeline it writes, and the
 * one move a clerk does not get to choose.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderWorkflowTest extends TestCase
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

    /** Someone allowed to move an order anywhere the map allows. */
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
            PermissionName::MarkOrdersDelivered,
            PermissionName::RecordCourierReturn,
            PermissionName::RecordCarrierReturn,
            PermissionName::RecordOfficeReturn,
            PermissionName::ResendOrders,
            PermissionName::SettleOrders,
            PermissionName::CancelOrders,
        );
    }

    private function move(array $headers, Order $order, OrderStatus $to, ?string $reason = null): TestResponse
    {
        return $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/status",
            array_filter([
                'status' => $to->value,
                'reason' => $reason,
                // Sending a parcel out names the carrier, and whichever of the two handover
                // statuses is the deducting one names the warehouse stock leaves — every test
                // that does either would otherwise be a test about that rather than about what it
                // is checking. Both rules are pinned by their own tests, in
                // OrderTransitionFieldsTest, OrderReadyToPrintTest and below.
                //
                // The warehouse goes *only* where the move actually offers it: the request refuses
                // a key the transition never described, so sending one to an order whose stock has
                // already gone would fail with a 422 about the wrong thing entirely.
                'fields' => match (true) {
                    $to->isDispatch() && ! $order->fulfilment_type->isOfficePickup() => ['shipping_company_id' => $this->carrier()->id],
                    ($to === OrderStatus::ReadyToPrint || $to === OrderStatus::Ready)
                        && $order->stock_deducted_at === null => ['warehouse_id' => $this->warehouse()->id],
                    default => null,
                },
            ]),
        );
    }

    /**
     * Walks a new order to whichever production status the test actually wants.
     *
     * **The press is entered through one door now.** «جديدة» hands over to «جاهزة للطباعة» — the
     * warehouse weighing the goods and letting them go — and the press picks the order up from
     * there. A test that only wants an order *at* the machine should not have to say so twice, so
     * the handover lives here rather than in twenty arrange blocks.
     */
    private function toPress(array $headers, Order $order, OrderStatus $to = OrderStatus::Printing): TestResponse
    {
        $this->move($headers, $order, OrderStatus::ReadyToPrint)->assertOk();

        return $this->move($headers, $order->refresh(), $to);
    }

    /** One carrier, made once and reused, so a fixture is never the subject of the test. */
    private function carrier(): ShippingCompany
    {
        return ShippingCompany::query()->firstOr(fn () => ShippingCompany::factory()->create());
    }

    /** One warehouse, made once and reused, so a fixture is never the subject of the test. */
    private function warehouse(): Warehouse
    {
        return Warehouse::query()->firstOr(fn () => Warehouse::factory()->create());
    }

    // ───────────────────────────── moving an order ─────────────────────────────

    public function test_a_new_order_can_start_printing(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $headers = $this->foreman();

        // Act
        $response = $this->toPress($headers, $order);

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.status', 'printing')
            ->assertJsonPath('message', 'تم نقل الطلبية إلى «قيد الطباعة»');
    }

    public function test_a_move_that_is_not_on_the_map_is_refused(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $headers = $this->foreman();

        // Act — straight from new to delivered.
        $response = $this->move($headers, $order, OrderStatus::Delivered);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    public function test_a_design_can_never_skip_printing(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::Designing)->create();
        $headers = $this->foreman();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Ready);

        // Assert — the one rule stated outright when the flow was described.
        $response->assertStatus(422);
        $this->assertSame(OrderStatus::Designing, $order->fresh()->status);
    }

    public function test_a_shortage_rejoins_the_work_it_was_parked_off(): void
    {
        // Arrange
        $short = Order::factory()->status(OrderStatus::Shortage)->create();
        $stillShort = Order::factory()->status(OrderStatus::Shortage)->create();
        $alsoShort = Order::factory()->status(OrderStatus::Shortage)->create();
        $headers = $this->foreman();

        // Act
        $handedOver = $this->move($headers, $short, OrderStatus::ReadyToPrint);
        $tooFar = $this->move($headers, $stillShort, OrderStatus::Ready);
        $straightToThePress = $this->move($headers, $alsoShort, OrderStatus::Printing);

        // Assert — the stock arrives and the order rejoins the road, **at the handover it was
        // taken off before**. It no longer goes straight to a production status: the press is
        // entered through one door whether an order reaches it from «جديدة» or from «نواقص», and
        // the goods have to be weighed and let go of on the way. «جاهزة» was never among them —
        // nothing has been printed, so there is nothing on the shelf.
        $handedOver->assertOk()->assertJsonPath('data.status', 'ready_to_print');
        $tooFar->assertStatus(422);
        $straightToThePress->assertStatus(422);
    }

    public function test_a_returned_order_can_be_sent_out_again(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::ReturnedOffice)->create();
        $headers = $this->foreman();

        // Act — off the shelf and out again, then out by whichever route the address implies.
        $resent = $this->move($headers, $order, OrderStatus::Resend);
        $out = $this->move($headers, $order->refresh(), OrderStatus::OutForDelivery);

        // Assert — the parcel does not go back to «جاهزة»: it was never unmade, it came back.
        $resent->assertOk()->assertJsonPath('data.status', 'resend');
        $out->assertOk()->assertJsonPath('data.status', 'out_for_delivery');
    }

    public function test_a_delivered_order_is_closed(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::Delivered)->create();
        $headers = $this->foreman();

        // Act
        $response = $this->move($headers, $order, OrderStatus::ReturnedOffice, 'العميل رجّعها');

        // Assert — there is no returns policy, so this is deliberate rather than an omission.
        $response->assertStatus(422);
    }

    // ─────────────────────── the move a clerk does not choose ───────────────────────

    public function test_a_delivery_city_sends_the_order_out_for_delivery(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::Ready)->create();
        $headers = $this->foreman();

        // Act — the clerk names the wrong half of the pair.
        $response = $this->move($headers, $order, OrderStatus::OfficePickup);

        // Assert — the destination settles it, and the clerk is corrected rather than refused:
        // they did not choose between the two, so there is nothing to tell them off for.
        $response->assertOk()->assertJsonPath('data.status', 'out_for_delivery');
    }

    public function test_a_branch_order_waits_at_the_counter(): void
    {
        // Arrange
        $order = Order::factory()->officePickup()->status(OrderStatus::Ready)->create();
        $headers = $this->foreman();

        // Act — and this time the clerk names the other wrong half.
        $response = $this->move($headers, $order, OrderStatus::OutForDelivery);

        // Assert
        $response->assertOk()->assertJsonPath('data.status', 'office_pickup');
    }

    public function test_an_order_waiting_at_the_counter_has_no_return_route(): void
    {
        // Arrange
        $order = Order::factory()->officePickup()->status(OrderStatus::OfficePickup)->create();
        $headers = $this->foreman();

        // Act
        $response = $this->move($headers, $order, OrderStatus::ReturnedCourier);

        // Assert — it never left the building: there is no courier and no carrier.
        $response->assertStatus(422);
    }

    // ───────────────────────────── what a move records ─────────────────────────────

    public function test_cancelling_demands_a_reason(): void
    {
        // Arrange — from printing: «جديدة» is the one open status with no way to cancel.
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        $headers = $this->foreman();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Cancelled);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame(OrderStatus::Printing, $order->fresh()->status);
    }

    public function test_a_cancellation_keeps_its_reason(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        $headers = $this->foreman();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Cancelled, 'العميل تراجع');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'العميل تراجع');

        $this->assertNotNull($order->fresh()->cancelled_at);
    }

    public function test_every_move_lands_on_the_timeline(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $headers = $this->foreman();

        // Act
        $this->toPress($headers, $order)->assertOk();
        $this->move($headers, $order->fresh(), OrderStatus::Ready)->assertOk();

        // Assert
        $this->assertDatabaseHas('order_status_transitions', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::New->value,
            'to_status' => OrderStatus::ReadyToPrint->value,
        ]);
        $this->assertDatabaseHas('order_status_transitions', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::ReadyToPrint->value,
            'to_status' => OrderStatus::Printing->value,
        ]);
        $this->assertDatabaseHas('order_status_transitions', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::Printing->value,
            'to_status' => OrderStatus::Ready->value,
        ]);
    }

    public function test_a_milestone_stamps_its_own_column(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $headers = $this->foreman();

        // Act
        $this->toPress($headers, $order)->assertOk();

        // Assert
        $this->assertNotNull($order->fresh()->printing_started_at);
    }

    public function test_who_moved_the_order_is_recorded(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $headers = $this->foreman();

        // Act
        $this->toPress($headers, $order)->assertOk();

        // Assert
        $this->assertNotNull(
            $order->transitions()->where('to_status', OrderStatus::Printing)->sole()->user_id,
        );
    }

    // ───────────────────────────── one permission per move ─────────────────────────────

    public function test_a_move_needs_the_permission_that_status_costs(): void
    {
        // Arrange — allowed to print, but not to mark anything ready.
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        $headers = $this->auth(PermissionName::ViewOrders, PermissionName::MoveOrderToPrinting);

        // Act
        $response = $this->move($headers, $order, OrderStatus::Ready);

        // Assert
        $response->assertForbidden();
        $this->assertSame(OrderStatus::Printing, $order->fresh()->status);
    }

    public function test_one_grant_covers_both_ways_of_leaving_the_workshop(): void
    {
        // Arrange — a delivery coordinator with nothing but dispatch.
        $delivery = Order::factory()->status(OrderStatus::Ready)->create();
        $pickup = Order::factory()->officePickup()->status(OrderStatus::Ready)->create();
        $headers = $this->auth(PermissionName::ViewOrders, PermissionName::DispatchOrders);

        // Act
        $out = $this->move($headers, $delivery, OrderStatus::OutForDelivery);
        $counter = $this->move($headers, $pickup, OrderStatus::OutForDelivery);

        // Assert — the same button must not work for طرابلس and fail for قرجي.
        $out->assertOk()->assertJsonPath('data.status', 'out_for_delivery');
        $counter->assertOk()->assertJsonPath('data.status', 'office_pickup');
    }

    public function test_the_order_lists_only_the_moves_this_user_may_make(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::ReadyToPrint)->create();
        $headers = $this->auth(PermissionName::ViewOrders, PermissionName::MoveOrderToPrinting);

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert — the whole point of gating the app on the server: «جاهزة للطباعة» may also go
        // to designing and to cancelled, and this user may do neither.
        $response->assertOk()
            ->assertJsonCount(1, 'data.available_transitions')
            ->assertJsonPath('data.available_transitions.0.status', 'printing');
    }

    public function test_moving_an_order_needs_authentication(): void
    {
        // Arrange
        $order = Order::factory()->create();

        // Act
        $response = $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'printing']);

        // Assert
        $response->assertUnauthorized();
    }

    // ─────────────── what the app is told it may do ───────────────

    /**
     * The clerk presses one button. Offering both halves of the pair would put «استلام مكتب»
     * and «جاري التوصيل» side by side on a screen where tapping either produces whichever the
     * city implies — two buttons for one action, one of which appears to do nothing.
     */
    public function test_a_ready_order_offers_one_way_out_not_two(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::Ready)->create();
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert
        $offered = array_column($response->json('data.available_transitions'), 'status');

        $this->assertContains(OrderStatus::OutForDelivery->value, $offered);
        $this->assertNotContains(OrderStatus::OfficePickup->value, $offered);
    }

    public function test_a_ready_branch_order_offers_collection_not_delivery(): void
    {
        // Arrange
        $order = Order::factory()->officePickup()->status(OrderStatus::Ready)->create();
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert
        $offered = array_column($response->json('data.available_transitions'), 'status');

        $this->assertContains(OrderStatus::OfficePickup->value, $offered);
        $this->assertNotContains(OrderStatus::OutForDelivery->value, $offered);
    }

    public function test_collapsing_the_pair_does_not_drop_any_other_move(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::Ready)->create();
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert — ready has three legal targets; the two dispatch ones become one, so two.
        $offered = array_column($response->json('data.available_transitions'), 'status');

        $this->assertEqualsCanonicalizing([
            OrderStatus::OutForDelivery->value,
            OrderStatus::Cancelled->value,
        ], $offered);
    }

    public function test_the_offer_still_says_which_moves_need_a_reason(): void
    {
        // Arrange — printing, which is where cancelling first becomes possible.
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert — the app asks for the sentence before sending, so the server never has to
        // refuse a cancellation for a missing one.
        $offers = collect($response->json('data.available_transitions'))->keyBy('status');

        $this->assertTrue($offers[OrderStatus::Cancelled->value]['requires_reason']);
        $this->assertFalse($offers[OrderStatus::Ready->value]['requires_reason']);
    }

    // ─────────────────────── the journey a progress bar draws ───────────────────────

    public function test_the_journey_is_the_route_the_business_described(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert — جديدة → جاهزة للطباعة → قيد التصميم → قيد الطباعة → جاهزة → التسليم →
        // تم الاستلام → تم التسوية. The handover is on the line and not beside it, because every
        // printed order passes through it: it is the step where the warehouse finishes and the
        // press begins. The last step is money rather than bags, and it is on the line because an
        // order whose cash never came back is not a finished order.
        $steps = array_column($response->json('data.progress.steps'), 'status');

        $this->assertSame([
            OrderStatus::New->value,
            OrderStatus::ReadyToPrint->value,
            OrderStatus::Designing->value,
            OrderStatus::Printing->value,
            OrderStatus::Ready->value,
            OrderStatus::OutForDelivery->value,
            OrderStatus::Delivered->value,
            OrderStatus::Settled->value,
        ], $steps);
    }

    public function test_a_branch_order_shows_collection_where_delivery_would_be(): void
    {
        // Arrange
        $order = Order::factory()->officePickup()->create();
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert — one step, not a fork the reader has to resolve.
        $steps = array_column($response->json('data.progress.steps'), 'status');

        $this->assertContains(OrderStatus::OfficePickup->value, $steps);
        $this->assertNotContains(OrderStatus::OutForDelivery->value, $steps);
    }

    public function test_the_journey_marks_where_the_order_actually_is(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $headers = $this->foreman();
        $this->toPress($headers, $order)->assertOk();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert
        $steps = collect($response->json('data.progress.steps'))->keyBy('status');

        $this->assertSame('done', $steps[OrderStatus::New->value]['state']);
        $this->assertSame('current', $steps[OrderStatus::Printing->value]['state']);
        $this->assertSame('upcoming', $steps[OrderStatus::Ready->value]['state']);
        $this->assertFalse($response->json('data.progress.is_detour'));
    }

    /**
     * «نواقص» and the three رواجع are real places an order sits and no part of the route to
     * anywhere. A bar that placed them on the line would be drawing a road that does not exist.
     */
    public function test_a_shortage_is_a_detour_not_a_step(): void
    {
        // Arrange — a shortage says what is short, so the move carries a line's own field. It is
        // declared off «جديدة»: the stock was not there when the job was picked up.
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['quantity' => '100']);
        // Enough stock for entering `printing` to actually deduct it — the same warehouse
        // move() will name.
        WarehouseStock::factory()->quantity('1000')->create([
            'warehouse_id' => $this->warehouse()->id,
            'stock_item_id' => $this->shelfOf($item),
        ]);
        $headers = $this->foreman();

        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Shortage->value,
            'fields' => ["shortage_{$item->id}" => 40],
        ])->assertOk();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert — off the line, and no step claims to be current.
        $steps = array_column($response->json('data.progress.steps'), 'status');
        $states = array_column($response->json('data.progress.steps'), 'state');

        $this->assertNotContains(OrderStatus::Shortage->value, $steps);
        $this->assertTrue($response->json('data.progress.is_detour'));
        $this->assertNotContains('current', $states);
    }

    public function test_a_detour_still_shows_how_far_the_order_got(): void
    {
        // Arrange — out on the road, then refused and back with the courier.
        $order = Order::factory()->create();
        $headers = $this->foreman();
        $this->toPress($headers, $order)->assertOk();
        $this->move($headers, $order->fresh(), OrderStatus::Ready)->assertOk();
        $this->move($headers, $order->fresh(), OrderStatus::OutForDelivery)->assertOk();
        $this->move($headers, $order->fresh(), OrderStatus::ReturnedCourier, 'العميل غير متواجد')->assertOk();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert — read from the timeline, so a returned order does not draw an empty bar as
        // though nothing had ever happened to it.
        $steps = collect($response->json('data.progress.steps'))->keyBy('status');

        $this->assertSame('done', $steps[OrderStatus::Ready->value]['state']);
        $this->assertSame('done', $steps[OrderStatus::OutForDelivery->value]['state']);
        $this->assertSame('upcoming', $steps[OrderStatus::Delivered->value]['state']);
    }

    public function test_a_finished_order_has_walked_the_whole_line(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $headers = $this->foreman();
        $journey = [
            OrderStatus::ReadyToPrint, OrderStatus::Printing, OrderStatus::Ready,
            OrderStatus::OutForDelivery, OrderStatus::Delivered,
        ];

        foreach ($journey as $to) {
            $this->move($headers, $order->fresh(), $to)->assertOk();
        }

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert
        $steps = collect($response->json('data.progress.steps'))->keyBy('status');

        $this->assertSame('current', $steps[OrderStatus::Delivered->value]['state']);
        $this->assertFalse($response->json('data.progress.is_detour'));
    }

    public function test_a_cancelled_order_stops_where_the_work_stopped(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $headers = $this->foreman();
        $this->toPress($headers, $order)->assertOk();
        $this->move($headers, $order->fresh(), OrderStatus::Cancelled, 'العميل تراجع')->assertOk();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert — the honest picture of an order written off halfway.
        $steps = collect($response->json('data.progress.steps'))->keyBy('status');

        $this->assertSame('done', $steps[OrderStatus::Printing->value]['state']);
        $this->assertSame('upcoming', $steps[OrderStatus::Ready->value]['state']);
        $this->assertTrue($response->json('data.progress.is_detour'));
    }

    // ───────────────────────────── the artwork conversation ─────────────────────────────

    /**
     * @return array{0: Order, 1: CustomerDesign}
     */
    /**
     * An order in the middle of its artwork conversation.
     *
     * In «قيد التصميم» rather than «جديدة», because that is the one status a version may be
     * attached in — the status is named after the work. A brand-new order gets its first
     * version *with* the move into design, which `OrderTransitionFieldsTest` covers.
     */
    private function orderNeedingArtwork(): array
    {
        $customer = Customer::factory()->create();
        $order = Order::factory()->forCustomer($customer)->status(OrderStatus::Designing)->create([
            'design_source' => DesignSource::Customer,
            'city_id' => City::factory(),
        ]);
        $design = CustomerDesign::factory()->create(['customer_id' => $customer->getKey()]);

        return [$order, $design];
    }

    /**
     * The design step is **optional**, whatever the order says its artwork source is.
     *
     * This used to be a guard: an order whose `design_source` was anything but `none` could not
     * be printed without an approved version. It was removed rather than narrowed, because the
     * cases it refused are ordinary work — plain (سادة) bags with nothing printed on them, and
     * jobs whose artwork was settled before the order was ever taken. A guard that fires on the
     * normal case only teaches staff to invent a version to get past it.
     *
     * `design_source` still decides whether a design fee may be charged; it no longer decides
     * what the press is allowed to do.
     */
    public function test_printing_does_not_require_an_approved_design(): void
    {
        // Arrange — the customer supplied the artwork, and no version has been approved here.
        [$order] = $this->orderNeedingArtwork();
        $headers = $this->foreman();

        // Act — already in the designer's queue, so it goes to the press directly rather than
        // back through the handover: «جاهزة للطباعة» is how an order *enters* production.
        $response = $this->move($headers, $order, OrderStatus::Printing);

        // Assert
        $response->assertOk()->assertJsonPath('data.status', 'printing');
    }

    public function test_an_order_skips_the_design_step_entirely(): void
    {
        // Arrange — «جديدة», never sent to design at all.
        $order = Order::factory()->create(['design_source' => DesignSource::None]);
        $headers = $this->foreman();

        // Act
        $response = $this->toPress($headers, $order);

        // Assert
        $response->assertOk()->assertJsonPath('data.status', 'printing');
    }

    public function test_a_design_is_chosen_from_the_customers_library(): void
    {
        // Arrange
        [$order, $design] = $this->orderNeedingArtwork();
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/designs", [
            'customer_design_id' => $design->getKey(),
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'proposed');
    }

    public function test_another_customers_artwork_cannot_be_attached(): void
    {
        // Arrange
        [$order] = $this->orderNeedingArtwork();
        $strangers = CustomerDesign::factory()->create([
            'customer_id' => Customer::factory()->create()->getKey(),
        ]);
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/designs", [
            'customer_design_id' => $strangers->getKey(),
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('customer_design_id');
    }

    public function test_versions_are_numbered_as_the_conversation_goes(): void
    {
        // Arrange
        [$order, $first] = $this->orderNeedingArtwork();
        $second = CustomerDesign::factory()->create(['customer_id' => $order->customer_id]);
        $headers = $this->foreman();

        // Act
        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/designs", [
            'customer_design_id' => $first->getKey(),
        ])->assertCreated();

        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/designs", [
            'customer_design_id' => $second->getKey(),
        ]);

        // Assert
        $response->assertCreated()->assertJsonPath('data.version', 2);
    }

    /**
     * **A file may be attached to an order nobody has started, and that is the point.**
     *
     * The library is the customer's; the file was often agreed before the order was taken. The
     * old rule made «قيد التصميم» the only door, so recording an existing file meant walking the
     * order through a status naming work that was never done.
     */
    public function test_artwork_may_be_attached_to_an_order_still_new(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        $order = Order::factory()->forCustomer($customer)->create([
            'design_source' => DesignSource::Customer,
            'city_id' => City::factory(),
        ]);
        $design = CustomerDesign::factory()->create(['customer_id' => $customer->getKey()]);
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/designs", [
            'customer_design_id' => $design->getKey(),
        ]);

        // Assert — attached without the order moving.
        $response->assertCreated()->assertJsonPath('data.version', 1);
        $this->assertSame(OrderStatus::New, $order->refresh()->status);
    }

    /**
     * The other end of the line, unmoved: the press is running against a settled file.
     *
     * Changing it means sending the order back to «قيد التصميم» on purpose — a move somebody
     * makes and the timeline records.
     */
    public function test_artwork_is_still_refused_once_the_press_is_running(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        $order = Order::factory()->forCustomer($customer)->status(OrderStatus::Printing)->create([
            'design_source' => DesignSource::Customer,
            'city_id' => City::factory(),
        ]);
        $design = CustomerDesign::factory()->create(['customer_id' => $customer->getKey()]);
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/designs", [
            'customer_design_id' => $design->getKey(),
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('customer_design_id');
    }

    public function test_rejecting_a_design_demands_a_reason(): void
    {
        // Arrange
        [$order, $design] = $this->orderNeedingArtwork();
        $version = OrderDesign::factory()->create([
            'order_id' => $order->getKey(),
            'customer_design_id' => $design->getKey(),
        ]);
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/designs/{$version->id}/review", [
                'status' => OrderDesignStatus::Rejected->value,
            ]);

        // Assert — the whole value of keeping versions is knowing why one was replaced.
        $response->assertStatus(422)->assertJsonValidationErrors('rejection_reason');
    }

    public function test_a_rejection_keeps_the_reason_it_was_turned_down_for(): void
    {
        // Arrange
        [$order, $design] = $this->orderNeedingArtwork();
        $version = OrderDesign::factory()->create([
            'order_id' => $order->getKey(),
            'customer_design_id' => $design->getKey(),
        ]);
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/designs/{$version->id}/review", [
                'status' => OrderDesignStatus::Rejected->value,
                'rejection_reason' => 'الألوان غير مطابقة',
            ]);

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'الألوان غير مطابقة');
    }

    public function test_approving_a_design_unblocks_printing(): void
    {
        // Arrange
        [$order, $design] = $this->orderNeedingArtwork();
        $version = OrderDesign::factory()->create([
            'order_id' => $order->getKey(),
            'customer_design_id' => $design->getKey(),
        ]);
        $headers = $this->foreman();

        // Act
        $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/designs/{$version->id}/review", [
                'status' => OrderDesignStatus::Approved->value,
            ])->assertOk();

        $response = $this->move($headers, $order->fresh(), OrderStatus::Printing);

        // Assert
        $response->assertOk()->assertJsonPath('data.status', 'printing');
    }

    public function test_a_version_is_judged_once(): void
    {
        // Arrange
        [$order, $design] = $this->orderNeedingArtwork();
        $version = OrderDesign::factory()->approved()->create([
            'order_id' => $order->getKey(),
            'customer_design_id' => $design->getKey(),
        ]);
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/designs/{$version->id}/review", [
                'status' => OrderDesignStatus::Rejected->value,
                'rejection_reason' => 'غيّرنا رأينا',
            ]);

        // Assert — changing a verdict would erase the reason the next version exists.
        $response->assertStatus(422);
    }

    public function test_approving_a_new_version_supersedes_the_old_one(): void
    {
        // Arrange
        [$order, $first] = $this->orderNeedingArtwork();
        $second = CustomerDesign::factory()->create(['customer_id' => $order->customer_id]);

        $old = OrderDesign::factory()->approved()->create([
            'order_id' => $order->getKey(),
            'customer_design_id' => $first->getKey(),
            'version' => 1,
        ]);
        $new = OrderDesign::factory()->create([
            'order_id' => $order->getKey(),
            'customer_design_id' => $second->getKey(),
            'version' => 2,
        ]);
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/designs/{$new->id}/review", [
                'status' => OrderDesignStatus::Approved->value,
            ]);

        // Assert — one approved version per order, and the old one records what replaced it
        // rather than being blanked.
        $response->assertOk();
        $this->assertSame(OrderDesignStatus::Rejected, $old->fresh()->status);
        $this->assertStringContainsString('2', (string) $old->fresh()->rejection_reason);
    }

    public function test_managing_designs_needs_its_own_permission(): void
    {
        // Arrange
        [$order, $design] = $this->orderNeedingArtwork();
        $headers = $this->auth(PermissionName::ViewOrders, PermissionName::ManageOrders);

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/designs", [
            'customer_design_id' => $design->getKey(),
        ]);

        // Assert
        $response->assertForbidden();
    }

    public function test_another_orders_design_is_a_404(): void
    {
        // Arrange
        [$order, $design] = $this->orderNeedingArtwork();
        $elsewhere = OrderDesign::factory()->create();
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/designs/{$elsewhere->id}/review", [
                'status' => OrderDesignStatus::Approved->value,
            ]);

        // Assert — scoped bindings make this a 404 by construction, not by a check somebody
        // has to remember to write.
        $response->assertNotFound();
    }

    // ───────────────────────── stock leaves the warehouse, once ─────────────────────────

    private function stockOf(Warehouse $warehouse, int $stockItemId): string
    {
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $stockItemId)
            ->first();

        return (string) ($stock?->quantity ?? '0.000');
    }

    /**
     * The shelf an order line draws from.
     *
     * A line names a size; a warehouse holds a stock item. Read with an explicit query rather
     * than `$item->variant->stock_item_id`, because strict mode is on outside production and a
     * lazy load would throw rather than answer.
     */
    private function shelfOf(OrderItem $item): int
    {
        return (int) ProductVariant::query()
            ->whereKey($item->product_variant_id)
            ->value('stock_item_id');
    }

    public function test_entering_ready_deducts_every_lines_quantity_with_no_warehouse_quantity_entered(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['quantity' => '40']);
        $warehouse = Warehouse::factory()->create();
        WarehouseStock::factory()->quantity('100')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $this->shelfOf($item),
        ]);
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::ReadyToPrint->value,
            'fields' => ['warehouse_id' => $warehouse->id],
        ]);

        // Assert — sales unit and warehouse unit agree, so the ordered quantity is deducted as-is
        $response->assertOk();
        $this->assertSame('60.000', $this->stockOf($warehouse, $this->shelfOf($item)));
        $this->assertDatabaseHas('stock_movements', [
            'stock_item_id' => $this->shelfOf($item),
            'from_warehouse_id' => $warehouse->id,
            'movement_type' => 'order_fulfillment',
            'reference_id' => $order->id,
            'quantity' => '40.000',
        ]);
        $this->assertSame($warehouse->id, $order->fresh()->fulfillment_warehouse_id);
        $this->assertNotNull($order->fresh()->stock_deducted_at);
    }

    public function test_entering_ready_uses_the_employees_entered_warehouse_quantity(): void
    {
        // Arrange — 40 bags sold, but they're weighed together on a scale, not counted piece by
        // piece: the employee read 10 kg off the scale and typed that, not a per-piece factor.
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create([
            'quantity' => '40',
            'warehouse_quantity' => '10',
        ]);
        $warehouse = Warehouse::factory()->create();
        WarehouseStock::factory()->quantity('100')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $this->shelfOf($item),
        ]);
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::ReadyToPrint->value,
            'fields' => ['warehouse_id' => $warehouse->id],
        ]);

        // Assert — the entered 10 kg is deducted directly, not derived from the quantity of 40
        $response->assertOk();
        $this->assertSame('90.000', $this->stockOf($warehouse, $this->shelfOf($item)));
        $this->assertDatabaseHas('stock_movements', [
            'stock_item_id' => $this->shelfOf($item),
            'from_warehouse_id' => $warehouse->id,
            'quantity' => '10.000',
        ]);
    }

    /**
     * A run sold by the piece off a shelf counted in kilograms, printed and waiting to be
     * weighed.
     *
     * @return array{0: Order, 1: OrderItem, 2: Warehouse}
     */
    private function runWeighedOffTheShelf(string $onTheShelf = '100.000'): array
    {
        // **The two units live in two places now.** The customer is billed by the piece
        // (`products.pricing_unit`); the pile is counted by the kilo (`stock_items.unit`). That
        // pair used to be two columns on one product — see the note on `OrderItem::stockUnit()`
        // for why the shelf's half moved to the shelf.
        $product = Product::factory()->create(['pricing_unit' => PricingUnit::Piece]);
        $shelf = StockItem::factory()->unit(PricingUnit::Kilogram)->create();
        $variant = ProductVariant::factory()->for($product)->create([
            'label' => '25*35',
            'stock_item_id' => $shelf->id,
        ]);
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => '500',
            'pricing_unit' => PricingUnit::Piece,
        ]);
        $warehouse = Warehouse::factory()->create();
        WarehouseStock::factory()->quantity($onTheShelf)->unit(PricingUnit::Kilogram)->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $shelf->id,
        ]);

        return [$order, $item, $warehouse];
    }

    public function test_the_weight_typed_at_ready_is_what_leaves_the_shelf(): void
    {
        // Arrange — 500 bags agreed with the customer, and the parcel goes on a scale: 12.5 kg.
        [$order, $item, $warehouse] = $this->runWeighedOffTheShelf();
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Ready->value,
            'fields' => [
                'warehouse_id' => $warehouse->id,
                "warehouse_quantity_{$item->getKey()}" => '12.5',
            ],
        ]);

        // Assert — the shelf loses the weight, not the piece count, and the line keeps the
        // figure so every cost computed behind it is computed on the same physical amount.
        $response->assertOk();
        $this->assertSame('87.500', $this->stockOf($warehouse, $this->shelfOf($item)));
        $this->assertSame('12.500', (string) $item->fresh()->warehouse_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'stock_item_id' => $this->shelfOf($item),
            'from_warehouse_id' => $warehouse->id,
            'quantity' => '12.500',
        ]);
    }

    public function test_a_run_stocked_in_another_unit_will_not_be_shelved_unweighed(): void
    {
        // Arrange — the same run against a shelf deep enough that deducting the *piece* count
        // would go through unnoticed, which is exactly what used to happen. And nobody weighs it.
        [$order, $item, $warehouse] = $this->runWeighedOffTheShelf('1000.000');
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Ready->value,
            'fields' => ['warehouse_id' => $warehouse->id],
        ]);

        // Assert — refused, and nothing moved: deducting 500 off a kilogram shelf because the
        // box was left empty is the mistake this whole field exists to prevent.
        $response->assertStatus(422);
        $this->assertSame('1000.000', $this->stockOf($warehouse, $this->shelfOf($item)));
        $this->assertSame(OrderStatus::Printing, $order->fresh()->status);
    }

    public function test_stock_already_deducted_does_not_deduct_stock_a_second_time(): void
    {
        // Arrange — `ready` has no way back to `printing`/`designing` (see
        // `OrderStatus::allowedNext()`), so unlike the old `printing`-triggered guard this cannot
        // be exercised by a real reprint any more: an order that has reached `ready` never sees
        // this transition fire again through legitimate use. What is left to test is the guard
        // itself — `stock_deducted_at !== null` — by constructing the order in the state a second
        // `ready` entry would otherwise find it in.
        $warehouse = Warehouse::factory()->create();
        $order = Order::factory()->status(OrderStatus::Printing)->create([
            'stock_deducted_at' => now(),
            'fulfillment_warehouse_id' => $warehouse->id,
        ]);
        $item = OrderItem::factory()->for($order)->create(['quantity' => '40']);
        WarehouseStock::factory()->quantity('100')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $this->shelfOf($item),
        ]);
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Ready->value,
        ]);

        // Assert — the guard refuses to deduct a second time: the shelf and the movement count
        // are exactly as this synthetic "already deducted" setup left them.
        $response->assertOk();
        $this->assertSame('100.000', $this->stockOf($warehouse, $this->shelfOf($item)));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_insufficient_stock_refuses_the_whole_transition(): void
    {
        // Arrange — only 5 on the shelf, the order wants 40
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['quantity' => '40']);
        $warehouse = Warehouse::factory()->create();
        WarehouseStock::factory()->quantity('5')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $this->shelfOf($item),
        ]);
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::ReadyToPrint->value,
            'fields' => ['warehouse_id' => $warehouse->id],
        ]);

        // Assert — nothing here landed: not the movement, not the balance, not the status
        $response->assertStatus(422);
        $this->assertSame('5.000', $this->stockOf($warehouse, $this->shelfOf($item)));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
        $this->assertNull($order->fresh()->stock_deducted_at);
    }

    public function test_a_shortfall_names_the_shelf_it_is_short_of(): void
    {
        // Arrange — two numbers and no name sent the storekeeper looking for what the refusal was
        // about. It names the *pile*, not the line's «المنتج — المقاس»: two different products can
        // be the reason one shelf ran out, so naming either would send them to the wrong place.
        $shelf = StockItem::factory()->named('كيس شحن')->size(25, 35)->create();
        $variant = ProductVariant::factory()->drawingFrom($shelf)->create();
        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'quantity' => '40',
            'product_name' => 'كيس شحن',
            'variant_label' => '25*35',
        ]);
        $warehouse = Warehouse::factory()->create();
        WarehouseStock::factory()->quantity('5')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $shelf->id,
        ]);
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::ReadyToPrint->value,
            'fields' => ['warehouse_id' => $warehouse->id],
        ]);

        // Assert
        $response->assertStatus(422);
        $this->assertSame(
            'الكمية المتوفرة من «كيس شحن 25*35» في المخزن (5.000) لا تكفي للكمية المطلوبة (40.000)',
            $response->json('message'),
        );
    }

    public function test_two_products_sharing_a_shelf_are_weighed_against_it_together(): void
    {
        // Arrange — **the reason this whole change exists.** كيس شحن سادة 25*35 and
        // كيس شحن مطبوع 25*35 are two products and one pile of bags. 300 of one and 400 of the
        // other each fit inside a shelf of 500 on their own; the order does not.
        //
        // Before stock items these were two separate balances, so this order passed both checks
        // and came up short on the floor. There was no arrangement of the old code that could
        // have caught it.
        $shelf = StockItem::factory()->named('كيس شحن')->size(25, 35)->create();
        $plain = ProductVariant::factory()->drawingFrom($shelf)->create();
        $printed = ProductVariant::factory()->drawingFrom($shelf)->create();

        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $plain->product_id,
            'product_variant_id' => $plain->id,
            'quantity' => '300',
            'product_name' => 'كيس شحن سادة',
            'variant_label' => '25*35',
            'sort_order' => 0,
        ]);
        OrderItem::factory()->for($order)->create([
            'product_id' => $printed->product_id,
            'product_variant_id' => $printed->id,
            'quantity' => '400',
            'product_name' => 'كيس شحن مطبوع',
            'variant_label' => '25*35',
            'sort_order' => 1,
        ]);

        $warehouse = Warehouse::factory()->create();
        WarehouseStock::factory()->quantity('500')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $shelf->id,
        ]);
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::ReadyToPrint->value,
            'fields' => ['warehouse_id' => $warehouse->id],
        ]);

        // Assert — one shortfall naming the shelf, for the two lines added together, and nothing
        // landed
        $response->assertStatus(422);
        $this->assertSame(
            'الكمية المتوفرة من «كيس شحن 25*35» في المخزن (500.000) لا تكفي للكمية المطلوبة (700.000)',
            $response->json('message'),
        );
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('500.000', $this->stockOf($warehouse, $shelf->id));
    }

    public function test_two_products_sharing_a_shelf_both_draw_from_the_one_balance(): void
    {
        // Arrange — the same pair, this time with enough on the shelf: 300 + 400 out of 800
        $shelf = StockItem::factory()->named('كيس شحن')->size(25, 35)->create();
        $plain = ProductVariant::factory()->drawingFrom($shelf)->create();
        $printed = ProductVariant::factory()->drawingFrom($shelf)->create();

        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $plain->product_id,
            'product_variant_id' => $plain->id,
            'quantity' => '300',
            'sort_order' => 0,
        ]);
        OrderItem::factory()->for($order)->create([
            'product_id' => $printed->product_id,
            'product_variant_id' => $printed->id,
            'quantity' => '400',
            'sort_order' => 1,
        ]);

        $warehouse = Warehouse::factory()->create();
        WarehouseStock::factory()->quantity('800')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $shelf->id,
        ]);
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::ReadyToPrint->value,
            'fields' => ['warehouse_id' => $warehouse->id],
        ]);

        // Assert — one shelf, 700 off it, two ledger rows because there are two lines to cost
        $response->assertOk();
        $this->assertSame('100.000', $this->stockOf($warehouse, $shelf->id));
        $this->assertSame(2, StockMovement::query()
            ->where('stock_item_id', $shelf->id)
            ->where('movement_type', 'order_fulfillment')
            ->count());
    }

    public function test_every_short_shelf_is_listed_not_only_the_first_one_reached(): void
    {
        // Arrange — one shelf short, a second with no balance there at all. Deducting line by
        // line would have refused on the first and never looked at the second.
        $shipping = StockItem::factory()->named('كيس شحن')->size(25, 35)->create();
        $nylon = StockItem::factory()->named('كيس نايلون')->size(30, 40)->create();
        $shippingVariant = ProductVariant::factory()->drawingFrom($shipping)->create();
        $nylonVariant = ProductVariant::factory()->drawingFrom($nylon)->create();

        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $shippingVariant->product_id,
            'product_variant_id' => $shippingVariant->id,
            'quantity' => '40',
            'product_name' => 'كيس شحن',
            'variant_label' => '25*35',
            'sort_order' => 0,
        ]);
        OrderItem::factory()->for($order)->create([
            'product_id' => $nylonVariant->product_id,
            'product_variant_id' => $nylonVariant->id,
            'quantity' => '80',
            'product_name' => 'كيس نايلون',
            'variant_label' => '30*40',
            'sort_order' => 1,
        ]);
        $warehouse = Warehouse::factory()->create();
        WarehouseStock::factory()->quantity('5')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $shipping->id,
        ]);
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::ReadyToPrint->value,
            'fields' => ['warehouse_id' => $warehouse->id],
        ]);

        // Assert — both shelves named, in the order the lines read, and still nothing landed
        $response->assertStatus(422);
        $this->assertSame('لا يوجد رصيد كافٍ في المخزن للمواد التالية', $response->json('message'));
        $this->assertSame([
            '«كيس شحن 25*35»: المتوفر (5.000) والمطلوب (40.000)',
            '«كيس نايلون 30*40»: المتوفر (0.000) والمطلوب (80.000)',
        ], $response->json('errors')['fields.warehouse_id']);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    public function test_two_lines_of_the_same_size_are_weighed_against_the_shelf_together(): void
    {
        // Arrange — 50 on the shelf and two lines of 30 of the same size, of the *same* product.
        // Either line alone fits; the order does not. The sibling test above proves the same
        // holds when the two lines are two different products sharing one shelf.
        $order = Order::factory()->create();
        $first = OrderItem::factory()->for($order)->create([
            'quantity' => '30',
            'product_name' => 'كيس شحن',
            'variant_label' => '25*35',
            'sort_order' => 0,
        ]);
        OrderItem::factory()->for($order)->create([
            'product_id' => $first->product_id,
            'product_variant_id' => $first->product_variant_id,
            'quantity' => '30',
            'product_name' => 'كيس شحن',
            'variant_label' => '25*35',
            'sort_order' => 1,
        ]);
        $warehouse = Warehouse::factory()->create();
        WarehouseStock::factory()->quantity('50')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $this->shelfOf($first),
        ]);
        $headers = $this->foreman();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::ReadyToPrint->value,
            'fields' => ['warehouse_id' => $warehouse->id],
        ]);

        // Assert — the shelf is named once, against everything the order asks of it
        $response->assertStatus(422);
        $this->assertStringContainsString(
            'في المخزن (50.000) لا تكفي للكمية المطلوبة (60.000)',
            (string) $response->json('message'),
        );
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_entering_ready_without_a_warehouse_is_refused(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        $headers = $this->foreman();

        // Act — the first entry into ready, with no warehouse named
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Ready->value,
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('fields.warehouse_id');
    }
}
