<?php

declare(strict_types=1);

namespace Tests\Feature\Carrier;

use App\Domain\Carrier\CarrierService;
use App\Domain\Carrier\Exceptions\CityHasNoNawrisMapping;
use App\Domain\Carrier\Exceptions\NawrisRejectedRequest;
use App\Domain\Carrier\Exceptions\OrderAlreadyHasAnOpenParcel;
use App\Domain\Carrier\Exceptions\OrderCannotBeDispatchedToNawris;
use App\Domain\Carrier\Models\NawrisParcel;
use App\Domain\Carrier\Models\NawrisParcelOrder;
use App\Domain\Delivery\Enums\FulfilmentType;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use App\Domain\Delivery\Models\ShippingCompany;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Handing an order to Nawris.
 *
 * **The money assertions are the point of this file.** The COD we ask them to collect is the
 * order's remainder *less our delivery fee*, and getting either half wrong bills a customer
 * twice — see NAWRIS-INTEGRATION.md §5.2. Everything else here is a precondition the contract
 * tells us to enforce ourselves, because their API will not.
 *
 * `Http::fake()` throughout — no test reaches the carrier.
 *
 * Arrange - Act - Assert throughout.
 */
class NawrisDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.nawris.authentication_key', 'key');
        config()->set('services.nawris.main_client_code', 'client');
        config()->set('services.nawris.base_url', 'https://carrier.test/external-api/');
        config()->set('services.nawris.log_channel', 'null');
    }

    private function accepted(string $code = '3702994', string $barCode = 'B-1'): void
    {
        Http::fake(['*' => Http::response([
            'success' => 1,
            'result' => ['code' => $code, 'bar_code' => $barCode],
        ], 200)]);
    }

    /**
     * An order going out for delivery to a mapped city, priced so the arithmetic is legible:
     * 100 of bags, with a 20 delivery fee that is stated on the order and part of no total —
     * the courier bills it to the customer at the door.
     */
    private function order(string $paid = '0.00', ?string $areaId = '204'): Order
    {
        $city = City::factory()->create([
            'fulfilment_type' => FulfilmentType::Delivery,
            'nawris_government_id' => '5',
            'delivery_price' => '20.00',
        ]);

        $region = Region::factory()->create(['city_id' => $city->id, 'nawris_area_id' => $areaId]);

        return Order::factory()->create([
            'city_id' => $city->id,
            'region_id' => $region->id,
            'city_name' => $city->name,
            // Where an order stands when «إرسال للنورس» is pressed.
            'status' => OrderStatus::Ready,
            'fulfilment_type' => FulfilmentType::Delivery,
            'items_total' => '100.00',
            'delivery_price' => '20.00',
            'grand_total' => '100.00',
            'paid_amount' => $paid,
        ]);
    }

    private function carrier(): CarrierService
    {
        return app(CarrierService::class);
    }

    // ── what may be handed over ──────────────────────────────────────────────────────────

    public function test_an_order_still_in_production_is_not_handed_to_a_courier(): void
    {
        // Arrange — the bags are not made yet, so there is nothing for a courier to carry.
        $this->accepted();
        $order = $this->order();
        $order->forceFill(['status' => OrderStatus::Printing])->save();

        // Assert
        $this->expectException(OrderCannotBeDispatchedToNawris::class);

        // Act
        $this->carrier()->dispatchOrder($order);
    }

    public function test_a_ready_order_is_handed_over(): void
    {
        // Arrange — «جاهزة» is one step from the road, which is the moment the button exists for.
        $this->accepted();
        $order = $this->order();

        // Act
        $parcel = $this->carrier()->dispatchOrder($order);

        // Assert
        $this->assertSame('3702994', $parcel->code);
    }

    public function test_a_returned_order_going_out_again_may_be_handed_over(): void
    {
        // Arrange — «إعادة إرسال» is the other status one step from the road, and an order that
        // came back and is going out again needs a second parcel.
        $this->accepted();
        $order = $this->order();
        $order->forceFill(['status' => OrderStatus::Resend])->save();

        // Act
        $parcel = $this->carrier()->dispatchOrder($order);

        // Assert
        $this->assertSame('3702994', $parcel->code);
    }

    // ── the money ────────────────────────────────────────────────────────────────────────

    public function test_the_cod_is_the_remainder_with_nothing_taken_off_for_delivery(): void
    {
        // Arrange — nothing paid yet: 100 owed for the bags, and delivery is in no total of ours.
        $this->accepted();
        $order = $this->order();

        // Act
        $this->carrier()->dispatchOrder($order);

        // Assert — the whole remainder. The courier adds their own fee at the door on top of it,
        // which is the only time the customer is billed for the trip.
        Http::assertSent(fn ($request) => (float) $request->data()['amount_to_be_collected'] === 100.0);
        $this->assertSame('100.00', (string) NawrisParcel::query()->sole()->amount_to_collect);
    }

    public function test_a_deposit_already_taken_comes_off_the_cod(): void
    {
        // Arrange — 30 paid at the counter before dispatch. The contract's field rule #1: a COD
        // that ignored this would re-bill money the customer has already handed over.
        $this->accepted();
        $order = $this->order(paid: '30.00');

        // Act
        $this->carrier()->dispatchOrder($order);

        // Assert — 100 − 30.
        Http::assertSent(fn ($request) => (float) $request->data()['amount_to_be_collected'] === 70.0);
    }

    public function test_no_delivery_fee_is_recorded_as_deducted_from_the_parcel(): void
    {
        // Arrange — the deduction is gone, because the fee it deducted is in no total of ours.
        $this->accepted();
        $order = $this->order();

        // Act
        $this->carrier()->dispatchOrder($order);

        // Assert — the column stays for the parcels dispatched under the old arrangement, and
        // reads zero for every parcel dispatched under this one.
        $this->assertSame('0.00', (string) NawrisParcel::query()->sole()->delivery_price_deducted);
    }

    public function test_a_fully_prepaid_order_sends_no_cod_and_still_bills_the_trip_to_the_customer(): void
    {
        // Arrange — the bags are paid for, and the trip never was: it is not on this order.
        $this->accepted();
        $order = $this->order(paid: '100.00');

        // Act
        $this->carrier()->dispatchOrder($order);

        // Assert — nothing to collect for us, and the courier still collects their own fee.
        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return (float) $body['amount_to_be_collected'] === 0.0
                && (int) $body['shipment_on_sender'] === 0;
        });
    }

    public function test_an_ordinary_order_leaves_the_fee_on_the_customer(): void
    {
        // Arrange
        $this->accepted();
        $order = $this->order();

        // Act
        $this->carrier()->dispatchOrder($order);

        // Assert — their default, and what the contract describes.
        Http::assertSent(fn ($request) => (int) $request->data()['shipment_on_sender'] === 0);
    }

    // ── the payload ──────────────────────────────────────────────────────────────────────

    public function test_the_receiver_is_the_order_code_not_a_person(): void
    {
        // Arrange — it is what gets read off the label at handover.
        $this->accepted();
        $order = $this->order();

        // Act
        $this->carrier()->dispatchOrder($order);

        // Assert
        Http::assertSent(fn ($request) => $request->data()['receiver'] === (string) $order->code);
    }

    public function test_the_destination_comes_from_the_order_s_own_city_and_region(): void
    {
        // Arrange — nobody picks a Nawris destination by hand.
        $this->accepted();
        $order = $this->order();

        // Act
        $this->carrier()->dispatchOrder($order);

        // Assert
        Http::assertSent(fn ($request) => $request->data()['government'] === '5'
            && $request->data()['area'] === '204');

        $parcel = NawrisParcel::query()->sole();
        $this->assertSame('5', $parcel->government);
        $this->assertSame('204', $parcel->area);
    }

    public function test_an_absent_area_is_stripped_rather_than_sent_as_null(): void
    {
        // Arrange — a null field is *ignored* by Nawris rather than cleared, so sending one would
        // be a silent no-op dressed as an instruction.
        $this->accepted();
        $order = $this->order(areaId: null);

        // Act
        $this->carrier()->dispatchOrder($order);

        // Assert
        Http::assertSent(fn ($request) => ! array_key_exists('area', $request->data()));
    }

    // ── what came back ───────────────────────────────────────────────────────────────────

    public function test_the_parcel_and_its_link_are_recorded(): void
    {
        // Arrange
        $this->accepted(code: '999', barCode: 'BAR-9');
        $order = $this->order();

        // Act
        $parcel = $this->carrier()->dispatchOrder($order);

        // Assert
        $this->assertSame('999', $parcel->code);
        $this->assertSame('BAR-9', $parcel->bar_code);
        $this->assertNotNull($parcel->dispatched_at);
        $this->assertTrue($parcel->isOpen());
        $this->assertDatabaseHas('nawris_parcel_orders', [
            'nawris_parcel_id' => $parcel->id,
            'order_id' => $order->id,
            'amount_to_collect' => '100.00',
        ]);
    }

    // ── which of our carriers the parcel is filed under ──────────────────────────────────

    public function test_a_parcel_is_filed_under_the_configured_carrier(): void
    {
        // Arrange — the row the integration files its parcels under, named in `.env` so the
        // filters and reports that already read `shipping_companies` keep working.
        $nawris = ShippingCompany::factory()->create(['name' => 'النورس']);
        config()->set('services.nawris.shipping_company_id', (string) $nawris->id);
        $this->accepted();
        $order = $this->order();

        // Act
        $parcel = $this->carrier()->dispatchOrder($order);

        // Assert
        $this->assertSame($nawris->id, $parcel->shipping_company_id);
    }

    public function test_a_parcel_falls_back_to_the_carrier_the_business_named_as_its_default(): void
    {
        // Arrange — nothing configured, which is every deployment until somebody sets it.
        config()->set('services.nawris.shipping_company_id', null);
        $nawris = ShippingCompany::factory()->asDefault()->create(['name' => 'النورس']);
        $this->accepted();
        $order = $this->order();

        // Act
        $parcel = $this->carrier()->dispatchOrder($order);

        // Assert — «من الناقل» has one answer in the business, so an unset setting reads it
        // rather than filing the parcel under nobody and leaving two truths about one parcel.
        $this->assertSame($nawris->id, $parcel->shipping_company_id);
    }

    public function test_the_configured_carrier_wins_over_the_default(): void
    {
        // Arrange — the two answer different questions: the default is who we usually send
        // with, and the setting is who *this integration's* parcels belong to.
        $nawris = ShippingCompany::factory()->create(['name' => 'النورس']);
        ShippingCompany::factory()->asDefault()->create(['name' => 'درب السبيل']);
        config()->set('services.nawris.shipping_company_id', (string) $nawris->id);
        $this->accepted();
        $order = $this->order();

        // Act
        $parcel = $this->carrier()->dispatchOrder($order);

        // Assert
        $this->assertSame($nawris->id, $parcel->shipping_company_id);
    }

    public function test_a_parcel_names_nobody_when_neither_is_set(): void
    {
        // Arrange
        config()->set('services.nawris.shipping_company_id', null);
        $this->accepted();
        $order = $this->order();

        // Act
        $parcel = $this->carrier()->dispatchOrder($order);

        // Assert — the parcel is still lodged: who it is filed under is bookkeeping, and
        // refusing a real shipment over it would be the tail wagging the dog.
        $this->assertNull($parcel->shipping_company_id);
    }

    public function test_the_reference_we_minted_is_what_we_stored(): void
    {
        // Arrange — it is the primary key a webhook matches on, so the value sent and the value
        // stored must be the same string.
        $this->accepted();
        $order = $this->order();

        // Act
        $parcel = $this->carrier()->dispatchOrder($order);

        // Assert
        Http::assertSent(fn ($request) => $request->data()['remote_order_id'] === $parcel->reference);
        $this->assertStringStartsWith($order->code.'_', $parcel->reference);
    }

    public function test_a_success_shaped_answer_with_no_code_is_refused(): void
    {
        // Arrange — a parcel row without a code can never be edited, cancelled or matched to a
        // webhook, so it must not be written at all.
        Http::fake(['*' => Http::response(['success' => 1, 'result' => []], 200)]);
        $order = $this->order();

        // Assert
        $this->expectException(NawrisRejectedRequest::class);

        // Act
        $this->carrier()->dispatchOrder($order);
    }

    public function test_nothing_is_recorded_when_the_carrier_refuses(): void
    {
        // Arrange
        Http::fake(['*' => Http::response(['success' => 0, 'error_msg' => 'لا'], 200)]);
        $order = $this->order();

        // Act
        try {
            $this->carrier()->dispatchOrder($order);
        } catch (NawrisRejectedRequest) {
            // asserted above; here we care what was left behind
        }

        // Assert
        $this->assertSame(0, NawrisParcel::query()->count());
        $this->assertDatabaseCount('nawris_parcel_orders', 0);
    }

    // ── the preconditions ────────────────────────────────────────────────────────────────

    public function test_an_office_pickup_order_is_never_handed_to_a_carrier(): void
    {
        // Arrange — it never leaves the building.
        Http::fake();
        $city = City::factory()->create([
            'fulfilment_type' => FulfilmentType::OfficePickup,
            'nawris_government_id' => '5',
        ]);
        $order = Order::factory()->create([
            'city_id' => $city->id,
            'city_name' => $city->name,
            'fulfilment_type' => FulfilmentType::OfficePickup,
        ]);

        // Assert
        $this->expectException(OrderCannotBeDispatchedToNawris::class);

        // Act
        $this->carrier()->dispatchOrder($order);
    }

    public function test_an_unmapped_city_is_refused_by_name_before_any_call(): void
    {
        // Arrange — an empty `government` would come back as whatever their validator says, which
        // is unreadable to the clerk who pressed the button.
        Http::fake();
        $city = City::factory()->create([
            'fulfilment_type' => FulfilmentType::Delivery,
            'nawris_government_id' => null,
        ]);
        $order = Order::factory()->create([
            'city_id' => $city->id,
            'city_name' => $city->name,
            'status' => OrderStatus::Ready,
            'fulfilment_type' => FulfilmentType::Delivery,
        ]);

        // Act
        $thrown = null;

        try {
            $this->carrier()->dispatchOrder($order);
        } catch (CityHasNoNawrisMapping $e) {
            $thrown = $e;
        }

        // Assert
        $this->assertNotNull($thrown);
        $this->assertStringContainsString($city->name, $thrown->getMessage());
        Http::assertNothingSent();
    }

    public function test_an_order_already_out_is_not_dispatched_twice(): void
    {
        // Arrange
        $this->accepted();
        $order = $this->order();
        $this->carrier()->dispatchOrder($order);

        // Assert
        $this->expectException(OrderAlreadyHasAnOpenParcel::class);

        // Act
        $this->carrier()->dispatchOrder($order->fresh());
    }

    public function test_an_order_whose_parcel_came_back_can_go_out_again(): void
    {
        // Arrange — the rule is "at most one *open* parcel", not "one ever". A closed parcel is
        // history, and keeping it is why the link table is not keyed on the order alone.
        //
        // A sequence rather than two `Http::fake()` calls: the second call *adds* a stub rather
        // than replacing the first, so the original `*` would go on answering. And a second
        // journey is genuinely a second code — a re-send arrives under a new one, often the old
        // one with an `N` suffix.
        Http::fake([
            '*' => Http::sequence()
                ->push(['success' => 1, 'result' => ['code' => '3702994', 'bar_code' => 'B-1']], 200)
                ->push(['success' => 1, 'result' => ['code' => '3702994N', 'bar_code' => 'B-2']], 200),
        ]);

        $order = $this->order();
        $first = $this->carrier()->dispatchOrder($order);
        $first->forceFill(['closed_at' => now()])->save();

        // Act
        $second = $this->carrier()->dispatchOrder($order->fresh());

        // Assert
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, NawrisParcelOrder::query()
            ->where('order_id', $order->id)->count());
    }

    // ── the mirror failure ───────────────────────────────────────────────────────────────

    public function test_an_order_that_went_out_but_was_never_lodged_is_findable(): void
    {
        // Arrange — because the carrier call happens after the status change commits, a failure
        // leaves an order out for delivery with no parcel and no webhook that will ever arrive.
        // Nothing is wrong with it; it simply is not lodged, and somebody has to see that.
        Http::fake(['*' => Http::response(['success' => 0, 'error_msg' => 'لا'], 200)]);
        $order = $this->order();
        $order->forceFill(['status' => OrderStatus::OutForDelivery])->save();

        try {
            $this->carrier()->dispatchOrder($order);
        } catch (NawrisRejectedRequest) {
            // the point is what is left behind
        }

        // Act
        $notLodged = $this->carrier()->ordersNotLodged()->pluck('id')->all();

        // Assert
        $this->assertSame([$order->id], $notLodged);
    }

    public function test_a_lodged_order_does_not_appear_in_the_not_lodged_queue(): void
    {
        // Arrange
        $this->accepted();
        $order = $this->order();
        $order->forceFill(['status' => OrderStatus::OutForDelivery])->save();
        $this->carrier()->dispatchOrder($order);

        // Act
        $notLodged = $this->carrier()->ordersNotLodged()->count();

        // Assert
        $this->assertSame(0, $notLodged);
    }
}
