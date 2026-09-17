<?php

declare(strict_types=1);

namespace Tests\Feature\Carrier;

use App\Domain\Carrier\Jobs\ProcessNawrisWebhook;
use App\Domain\Carrier\Models\NawrisParcel;
use App\Domain\Carrier\Models\NawrisParcelOrder;
use App\Domain\Carrier\Models\NawrisWebhookEvent;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The inbound half: the gate, the log, and the queue.
 *
 * **The handler does nothing but log, queue and return 200**, so these are tests about exactly
 * that — that the body is kept before anything is believed about it, that a duplicate is refused
 * by the database rather than by a comparison, and that an unmatched event still gets a row.
 *
 * The work itself is `NawrisStatusMappingTest`.
 *
 * Arrange - Act - Assert throughout.
 */
class NawrisWebhookTest extends TestCase
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
    private function send(array $body, ?string $token = self::SECRET): TestResponse
    {
        $headers = $token !== null ? ['Authorization' => 'Bearer '.$token] : [];

        return $this->withHeaders($headers)->postJson('/api/v1/webhooks/nawris', $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'remote_order_id' => 'ref-1',
            'order_code' => '3702994',
            'to_status_code' => 7,
            'to_status_text' => 'تم التسليم',
            'order_price' => '120.00',
        ], $overrides);
    }

    // ── the gate ─────────────────────────────────────────────────────────────────────────

    public function test_a_request_with_no_token_is_refused(): void
    {
        // Arrange
        Queue::fake();

        // Act
        $response = $this->send($this->body(), token: null);

        // Assert
        $response->assertStatus(401);
        $this->assertDatabaseCount('nawris_webhook_events', 0);
    }

    public function test_a_request_with_the_wrong_token_is_refused(): void
    {
        // Arrange
        Queue::fake();

        // Act
        $response = $this->send($this->body(), token: 'not-the-secret');

        // Assert
        $response->assertStatus(401);
    }

    public function test_nothing_can_authenticate_when_no_secret_is_configured(): void
    {
        // Arrange — an unset secret must mean "nobody", not "anybody". A blank compared against a
        // blank bearer would otherwise let the world in.
        config()->set('services.nawris.webhook_secret', '');
        Queue::fake();

        // Act
        $response = $this->send($this->body(), token: '');

        // Assert
        $response->assertStatus(401);
    }

    public function test_an_ip_outside_the_allowlist_is_refused(): void
    {
        // Arrange — the contract's own bug, fixed rather than copied: the allowlist is attached to
        // the route, not merely written.
        config()->set('services.nawris.webhook_ips', ['203.0.113.9']);
        Queue::fake();

        // Act
        $response = $this->send($this->body());

        // Assert
        $response->assertStatus(403);
    }

    public function test_an_ip_inside_the_allowlist_is_let_through(): void
    {
        // Arrange — the test client reports 127.0.0.1.
        config()->set('services.nawris.webhook_ips', ['127.0.0.1']);
        Queue::fake();

        // Act
        $response = $this->send($this->body());

        // Assert
        $response->assertOk();
    }

    // ── log, queue, 200 ──────────────────────────────────────────────────────────────────

    public function test_the_body_is_stored_verbatim_and_the_work_is_queued(): void
    {
        // Arrange — they do not re-send, so the payload is written before anything is believed
        // about it.
        Queue::fake();
        $body = $this->body();

        // Act
        $response = $this->send($body);

        // Assert
        $response->assertOk();

        $event = NawrisWebhookEvent::query()->sole();
        $this->assertSame($body, $event->payload);
        $this->assertSame('3702994', $event->code);
        $this->assertSame('ref-1', $event->reference);
        $this->assertSame(7, $event->status_code);
        $this->assertTrue($event->isPending());

        Queue::assertPushed(ProcessNawrisWebhook::class);
    }

    public function test_no_business_logic_runs_in_the_request_cycle(): void
    {
        // Arrange — a parcel that would match, so anything eager would move the order.
        Queue::fake();
        $order = Order::factory()->create(['status' => OrderStatus::OutForDelivery]);
        $parcel = NawrisParcel::factory()->create(['code' => '3702994']);
        NawrisParcelOrder::factory()->create([
            'nawris_parcel_id' => $parcel->id,
            'order_id' => $order->id,
        ]);

        // Act
        $this->send($this->body());

        // Assert — untouched: the queue has the work, the request did not do it.
        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
    }

    public function test_a_duplicate_is_answered_200_and_stored_once(): void
    {
        // Arrange — a re-send of the same news is routine. From their side nothing is wrong, and
        // answering anything but 200 would invite a retry loop.
        Queue::fake();
        $body = $this->body();

        // Act
        $first = $this->send($body);
        $second = $this->send($body);

        // Assert
        $first->assertOk();
        $second->assertOk();
        $this->assertDatabaseCount('nawris_webhook_events', 1);
    }

    public function test_two_different_events_are_both_stored(): void
    {
        // Arrange — the fingerprint covers the meaningful fields, so a genuine change is a
        // genuinely different event.
        Queue::fake();

        // Act
        $this->send($this->body(['to_status_code' => 3]));
        $this->send($this->body(['to_status_code' => 7]));

        // Assert
        $this->assertDatabaseCount('nawris_webhook_events', 2);
    }

    public function test_a_body_we_cannot_make_sense_of_is_still_stored(): void
    {
        // Arrange — validation here would answer 422 and throw the payload away, and they do not
        // re-send. Every body is kept and judged afterwards.
        Queue::fake();

        // Act
        $response = $this->send(['something' => 'unexpected']);

        // Assert
        $response->assertOk();
        $this->assertDatabaseCount('nawris_webhook_events', 1);
    }

    // ── the job, run for real ────────────────────────────────────────────────────────────

    public function test_an_unmatched_event_is_kept_with_a_null_parcel(): void
    {
        // Arrange — the whole reason that column is nullable. The system this was compiled from
        // logged these and dropped them.
        $this->send($this->body(['remote_order_id' => 'nobody', 'order_code' => 'nobody']));

        // Assert
        $event = NawrisWebhookEvent::query()->sole();
        $this->assertTrue($event->isUnmatched());
        $this->assertNotNull($event->processed_at);
        $this->assertNotNull($event->error);
    }

    public function test_an_event_is_matched_by_our_own_reference_first(): void
    {
        // Arrange
        $order = Order::factory()->create(['status' => OrderStatus::OutForDelivery]);
        $parcel = NawrisParcel::factory()->create(['reference' => 'ref-1', 'code' => 'other']);
        NawrisParcelOrder::factory()->create([
            'nawris_parcel_id' => $parcel->id,
            'order_id' => $order->id,
        ]);

        // Act
        $this->send($this->body(['to_status_code' => 3]));

        // Assert
        $this->assertSame($parcel->id, NawrisWebhookEvent::query()->sole()->nawris_parcel_id);
    }

    public function test_an_event_falls_back_to_the_parcel_code(): void
    {
        // Arrange — resends frequently arrive with no reference at all.
        $order = Order::factory()->create(['status' => OrderStatus::OutForDelivery]);
        $parcel = NawrisParcel::factory()->create(['code' => '3702994']);
        NawrisParcelOrder::factory()->create([
            'nawris_parcel_id' => $parcel->id,
            'order_id' => $order->id,
        ]);

        // Act
        $this->send($this->body(['remote_order_id' => null, 'to_status_code' => 3]));

        // Assert
        $this->assertSame($parcel->id, NawrisWebhookEvent::query()->sole()->nawris_parcel_id);
    }

    public function test_an_event_falls_back_to_the_barcode_last(): void
    {
        // Arrange — the label in the courier's hand still carries a barcode we recorded, even when
        // the parcel comes back under a code we have never seen.
        $order = Order::factory()->create(['status' => OrderStatus::OutForDelivery]);
        $parcel = NawrisParcel::factory()->create(['code' => 'unknown-code', 'bar_code' => 'SCAN-7']);
        NawrisParcelOrder::factory()->create([
            'nawris_parcel_id' => $parcel->id,
            'order_id' => $order->id,
        ]);

        // Act
        $this->send($this->body(['remote_order_id' => null, 'order_code' => 'SCAN-7', 'to_status_code' => 3]));

        // Assert
        $this->assertSame($parcel->id, NawrisWebhookEvent::query()->sole()->nawris_parcel_id);
    }
}
