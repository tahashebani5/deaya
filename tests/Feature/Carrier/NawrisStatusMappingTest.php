<?php

declare(strict_types=1);

namespace Tests\Feature\Carrier;

use App\Domain\Carrier\Models\NawrisParcel;
use App\Domain\Carrier\Models\NawrisParcelOrder;
use App\Domain\Carrier\Models\NawrisWebhookEvent;
use App\Domain\Order\Enums\OrderPaymentType;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What a webhook is allowed to do to an order, and the three guards in front of it.
 *
 * **Every incident in the contract has a test here.** These are the regressions: a duplicate that
 * paid a merchant twice, a parcel belonging to someone else that closed our order and released
 * money, a price check that blocked and froze orders at "with the courier" while collected cash
 * sat outside the books. Reimplementing the mapping without these is reimplementing the incidents.
 *
 * The arithmetic throughout: an order of 120 — 100 of bags and 20 of delivery. We ask Nawris for
 * 100 and they collect 118 at the door, the extra 18 being their own fee.
 *
 * Arrange - Act - Assert throughout.
 */
class NawrisStatusMappingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'shared-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.nawris.webhook_secret', self::SECRET);
        config()->set('services.nawris.webhook_ips', []);
        config()->set('services.nawris.log_channel', 'null');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(array $body): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.self::SECRET])
            ->postJson('/api/v1/webhooks/nawris', $body);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'remote_order_id' => 'ref-1',
            'order_code' => 'CODE-1',
            'to_status_code' => 7,
            'to_status_text' => 'تم التسليم',
            'order_price' => '118.00',
        ], $overrides);
    }

    /**
     * @return array{Order, NawrisParcel}
     */
    private function outForDelivery(OrderStatus $status = OrderStatus::OutForDelivery): array
    {
        $order = Order::factory()->create([
            'status' => $status,
            'items_total' => '100.00',
            'delivery_price' => '20.00',
            'grand_total' => '120.00',
        ]);

        $parcel = NawrisParcel::factory()->create([
            'reference' => 'ref-1',
            'code' => 'CODE-1',
            'amount_to_collect' => '100.00',
            'delivery_price_deducted' => '20.00',
        ]);

        NawrisParcelOrder::factory()->create([
            'nawris_parcel_id' => $parcel->id,
            'order_id' => $order->id,
            'amount_to_collect' => '100.00',
        ]);

        return [$order, $parcel];
    }

    // ── the happy path, and the money ────────────────────────────────────────────────────

    public function test_a_delivery_moves_the_order_and_closes_the_parcel(): void
    {
        // Arrange
        [$order, $parcel] = $this->outForDelivery();

        // Act
        $this->send($this->body());

        // Assert
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertNotNull($parcel->fresh()->closed_at);
        $this->assertSame('118.00', (string) $parcel->fresh()->collected_amount);
    }

    public function test_a_delivery_writes_the_cash_and_the_carrier_settlement(): void
    {
        // Arrange — the two entries that between them close a Nawris order without a write-off.
        [$order] = $this->outForDelivery();

        // Act
        $this->send($this->body());

        // Assert
        $order->refresh();
        $this->assertSame('100.00', (string) $order->paid_amount);
        $this->assertSame('20.00', (string) $order->carrier_settled_amount);
        $this->assertSame('0.00', (string) $order->written_off_amount);
        $this->assertSame('0.00', $order->remainingAmount());
        $this->assertSame(PaymentStatus::Paid, $order->paymentStatus());
    }

    public function test_the_delivery_fee_is_not_recorded_as_cash(): void
    {
        // Arrange — «كم قبضنا اليوم؟» must never contain money that went into a courier's pocket.
        [$order] = $this->outForDelivery();

        // Act
        $this->send($this->body());

        // Assert
        $cash = OrderPayment::query()
            ->where('order_id', $order->id)
            ->get()
            ->filter(fn (OrderPayment $p) => $p->type->movedCash())
            ->sum(fn (OrderPayment $p) => (float) $p->amount);

        $this->assertSame(100.0, $cash);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'type' => OrderPaymentType::CarrierSettled->value,
            'amount' => '20.00',
        ]);
    }

    public function test_the_order_can_then_be_settled_without_a_write_off(): void
    {
        // Arrange — the whole reason the carrier-settled entry exists. Without it this order would
        // stand in «تم الاستلام» for ever owing exactly its delivery fee.
        [$order] = $this->outForDelivery();

        // Act
        $this->send($this->body());

        // Assert
        $order->refresh();
        $this->assertFalse($order->paymentStatus()->isOutstanding());
        $this->assertContains(OrderStatus::Settled, $order->status->allowedNext($order->production_flow));
    }

    public function test_the_courier_phone_is_recorded(): void
    {
        // Arrange
        [$order] = $this->outForDelivery();

        // Act
        $this->send($this->body(['captain_phone' => '0912345678']));

        // Assert — last courier wins; it is the number you ring when the parcel is late.
        $this->assertSame('0912345678', $order->fresh()->courier_phone);
    }

    // ── guard 1: idempotency ─────────────────────────────────────────────────────────────

    public function test_a_repeated_delivery_does_not_pay_twice(): void
    {
        // Arrange — the incident this guard is named for. The second body differs slightly, so the
        // database fingerprint does *not* catch it: this is the status comparison doing the work.
        [$order] = $this->outForDelivery();
        $this->send($this->body());

        // Act
        $this->send($this->body(['order_price' => '118.50']));

        // Assert
        $order->refresh();
        $this->assertSame('100.00', (string) $order->paid_amount);
        $this->assertSame('20.00', (string) $order->carrier_settled_amount);
        $this->assertSame(1, OrderPayment::query()
            ->where('order_id', $order->id)
            ->where('type', OrderPaymentType::CarrierSettled->value)
            ->count());
    }

    public function test_the_idempotency_guard_compares_enums_not_strings(): void
    {
        // Arrange — the silent failure the contract names: `orders.status` is a cast enum, and an
        // enum compared to a plain string is *always* unequal, so the guard would pass everything
        // while looking like it works. Asserting the behaviour rather than the implementation.
        [$order] = $this->outForDelivery();

        // Act — a code 3 against an order already out for delivery is a no-op by this guard.
        $this->send($this->body(['to_status_code' => 3, 'order_price' => null]));

        // Assert
        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
        $this->assertSame(0, OrderPayment::query()->where('order_id', $order->id)->count());
    }

    // ── guard 2: delivery conflict ───────────────────────────────────────────────────────

    public function test_a_different_code_with_a_short_amount_blocks_everything(): void
    {
        // Arrange — the incident: a parcel belonging to a different customer arrived carrying our
        // correlation id, the order was closed as delivered and money was released, while our
        // goods were coming back unsold.
        [$order, $parcel] = $this->outForDelivery();

        // Act
        $this->send($this->body(['order_code' => 'SOMEONE-ELSE', 'order_price' => '10.00']));

        // Assert — no state change, no money, and a flag raised for a human.
        $order->refresh();
        $this->assertSame(OrderStatus::OutForDelivery, $order->status);
        $this->assertSame('0.00', (string) $order->paid_amount);
        $this->assertTrue($parcel->fresh()->hasOpenConflict());
        $this->assertNotNull(NawrisWebhookEvent::query()->sole()->error);
    }

    public function test_a_different_code_with_a_matching_amount_is_let_through(): void
    {
        // Arrange — a legitimate re-send also arrives under a new code, and freezing an order on
        // unproven suspicion is worse than a late review.
        [$order] = $this->outForDelivery();

        // Act
        $this->send($this->body(['order_code' => '3702994N']));

        // Assert
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertSame('100.00', (string) $order->fresh()->paid_amount);
    }

    public function test_a_clean_delivery_clears_an_older_conflict(): void
    {
        // Arrange — the one moment trustworthy enough to auto-clear: the parcel arrived under the
        // code we expected, carrying what we asked for.
        [$order, $parcel] = $this->outForDelivery();
        $parcel->forceFill(['conflict_raised_at' => now()->subDay()])->save();

        // Act
        $this->send($this->body());

        // Assert
        $this->assertFalse($parcel->fresh()->hasOpenConflict());
        $this->assertNotNull($parcel->fresh()->conflict_resolved_at);
    }

    // ── guard 3: price discrepancy — alert, never block ──────────────────────────────────

    public function test_a_short_collection_alerts_without_blocking(): void
    {
        // Arrange — an earlier version blocked, and froze orders at "with the courier" while cash
        // that had genuinely been collected sat outside the books.
        [$order] = $this->outForDelivery();

        // Act — same code, so no conflict; simply less than we asked for.
        $this->send($this->body(['order_price' => '90.00']));

        // Assert — the order moved, and the alert is on the event.
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertStringContainsString('نقص', (string) NawrisWebhookEvent::query()->sole()->error);
    }

    public function test_the_carrier_fee_on_top_is_not_a_discrepancy(): void
    {
        // Arrange — **the false positive that would otherwise fire on every single order.** The
        // courier adds their own fee at the door, so what comes back is always at least what we
        // asked for. The comparison is a floor, not an equality.
        [$order] = $this->outForDelivery();

        // Act — 100 asked, 118 collected: the extra 18 is theirs.
        $this->send($this->body(['order_price' => '118.00']));

        // Assert
        $this->assertNull(NawrisWebhookEvent::query()->sole()->error);
        $this->assertSame('100.00', (string) $order->fresh()->paid_amount);
    }

    // ── the state machine still decides ──────────────────────────────────────────────────

    public function test_a_move_the_machine_refuses_is_parked_rather_than_forced(): void
    {
        // Arrange — «جاري التوصيل» leads only to «تم الاستلام» and «راجع لدى المندوب». Forcing a
        // jump to «راجع مكتب» would record a parcel as being on our shelf while it is still in
        // somebody's van.
        [$order] = $this->outForDelivery();

        // Act — code 6, return received back.
        $this->send($this->body(['to_status_code' => 6, 'order_price' => null]));

        // Assert
        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
        $this->assertStringContainsString('غير مسموحة', (string) NawrisWebhookEvent::query()->sole()->error);
    }

    public function test_a_parked_event_still_keeps_everything_it_carried(): void
    {
        // Arrange — parking discards the *state change*, never the information. That is what makes
        // it resolvable later instead of merely lost.
        [$order, $parcel] = $this->outForDelivery();

        // Act
        $this->send($this->body(['to_status_code' => 6, 'to_status_text' => 'راجع', 'captain_phone' => '0910000001']));

        // Assert
        $parcel->refresh();
        $this->assertSame(6, $parcel->remote_status_code);
        $this->assertSame('راجع', $parcel->remote_status_text);
        $this->assertNotNull(NawrisWebhookEvent::query()->sole()->payload);
    }

    public function test_the_return_chain_walks_one_link_at_a_time(): void
    {
        // Arrange — each hand-over is a real event somebody is answerable for.
        [$order] = $this->outForDelivery();

        // Act
        $this->send($this->body(['to_status_code' => 15, 'remote_order_id' => 'ref-1', 'order_price' => null]));
        $this->assertSame(OrderStatus::ReturnedCourier, $order->fresh()->status);

        $this->send($this->body(['to_status_code' => 19, 'order_price' => null]));
        $this->assertSame(OrderStatus::ReturnedCarrier, $order->fresh()->status);

        $this->send($this->body(['to_status_code' => 6, 'order_price' => null]));

        // Assert
        $this->assertSame(OrderStatus::ReturnedOffice, $order->fresh()->status);
    }

    // ── the codes themselves ─────────────────────────────────────────────────────────────

    public function test_an_unmapped_code_changes_nothing_but_is_recorded(): void
    {
        // Arrange — the contract is explicit that unmapped codes exist and their meanings are
        // unknown. A carrier that invents one must never have it guessed at.
        [$order, $parcel] = $this->outForDelivery();

        // Act
        $this->send($this->body(['to_status_code' => 99, 'to_status_text' => 'شيء ما', 'order_price' => null]));

        // Assert
        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
        $this->assertSame(99, $parcel->fresh()->remote_status_code);
        $this->assertSame('شيء ما', $parcel->fresh()->remote_status_text);
        $this->assertStringContainsString('غير معروف', (string) NawrisWebhookEvent::query()->sole()->error);
    }

    public function test_code_four_puts_a_lodged_order_on_the_road(): void
    {
        // Arrange — «مع المندوب» in their table. A parcel is lodged while the order is «جاهزة»,
        // and this is the code that reports a courier now holds it.
        [$order] = $this->outForDelivery(OrderStatus::Ready);

        // Act
        $this->send($this->body(['to_status_code' => 4, 'order_price' => null]));

        // Assert
        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
    }

    public function test_code_four_repeated_on_an_order_already_out_changes_nothing(): void
    {
        // Arrange — the state a parcel spends most of its life in, so it arrives again and again.
        [$order] = $this->outForDelivery();

        // Act
        $this->send($this->body(['to_status_code' => 4, 'order_price' => null]));

        // Assert
        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
        $this->assertNull(NawrisWebhookEvent::query()->sole()->error);
    }

    public function test_code_four_with_a_return_reason_is_still_not_a_return(): void
    {
        // Arrange — the reading this replaces treated a reason on code 4 as a return, which
        // turned the ordinary out-for-delivery notice into a false «راجع لدى المندوب».
        [$order] = $this->outForDelivery();

        // Act
        $this->send($this->body([
            'to_status_code' => 4,
            'return_reason' => 'الزبون لم يرد',
            'order_price' => null,
        ]));

        // Assert
        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
    }

    public function test_code_three_at_their_company_moves_nothing(): void
    {
        // Arrange — «في الشركة» is their warehouse, not the road. Only a courier holding the
        // parcel makes it «جاري التوصيل», and that is code 4.
        [$order, $parcel] = $this->outForDelivery(OrderStatus::Ready);

        // Act
        $this->send($this->body(['to_status_code' => 3, 'to_status_text' => 'في الشركة', 'order_price' => null]));

        // Assert
        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
        $this->assertSame(3, $parcel->fresh()->remote_status_code);
        $this->assertSame('في الشركة', $parcel->fresh()->remote_status_text);
    }

    public function test_code_five_is_a_return_the_carrier_is_holding(): void
    {
        // Arrange — «مرتجع مع الشركة». The link before it in our chain has already been walked.
        [$order] = $this->outForDelivery(OrderStatus::ReturnedCourier);

        // Act
        $this->send($this->body(['to_status_code' => 5, 'order_price' => null]));

        // Assert
        $this->assertSame(OrderStatus::ReturnedCarrier, $order->fresh()->status);
    }

    public function test_code_five_from_the_road_is_parked_rather_than_skipping_a_link(): void
    {
        // Arrange — the chain of custody is walked one link at a time; the carrier does not walk
        // it for us.
        [$order] = $this->outForDelivery();

        // Act
        $this->send($this->body(['to_status_code' => 5, 'order_price' => null]));

        // Assert
        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
        $this->assertStringContainsString('نقلة غير مسموحة', (string) NawrisWebhookEvent::query()->sole()->error);
    }

    public function test_their_settlement_does_not_settle_our_order(): void
    {
        // Arrange — «تم التسوية» on their side is a statement about their books. Ours is our own
        // decision about ours, and the carrier does not get the last word on when we consider
        // ourselves paid.
        [$order] = $this->outForDelivery(OrderStatus::Delivered);

        // Act
        $this->send($this->body(['to_status_code' => 8, 'to_status_text' => 'تم التسوية', 'order_price' => null]));

        // Assert
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_their_settlement_is_recorded_rather_than_called_unknown(): void
    {
        // Arrange — ignored is not the same as unrecognised: their label lands on the parcel and
        // no error is written, which is what separates code 8 from a code they invented.
        [$order, $parcel] = $this->outForDelivery(OrderStatus::Delivered);

        // Act
        $this->send($this->body(['to_status_code' => 8, 'to_status_text' => 'تم التسوية', 'order_price' => null]));

        // Assert
        $this->assertSame(8, $parcel->fresh()->remote_status_code);
        $this->assertSame('تم التسوية', $parcel->fresh()->remote_status_text);
        $this->assertNull(NawrisWebhookEvent::query()->sole()->error);
    }

    public function test_a_write_off_cancels_even_though_the_webhook_carries_no_reason(): void
    {
        // Arrange — «إلغاء تام» demands a reason and `ChangeOrderStatus` throws without one, so
        // every code 12 would fail. It is reachable only once the parcel is back on our shelf.
        [$order] = $this->outForDelivery(OrderStatus::ReturnedOffice);

        // Act
        $this->send($this->body(['to_status_code' => 12, 'order_price' => null]));

        // Assert
        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertNotNull($order->cancellation_reason);
    }

    public function test_their_own_reason_is_used_when_they_send_one(): void
    {
        // Arrange
        [$order] = $this->outForDelivery(OrderStatus::ReturnedOffice);

        // Act
        $this->send($this->body([
            'to_status_code' => 12,
            'return_reason' => 'الزبون رفض الاستلام',
            'order_price' => null,
        ]));

        // Assert
        $this->assertSame('الزبون رفض الاستلام', $order->fresh()->cancellation_reason);
    }
}
