<?php

declare(strict_types=1);

namespace Tests\Feature\Carrier;

use App\Domain\Carrier\CarrierService;
use App\Domain\Carrier\Models\NawrisParcel;
use App\Domain\Carrier\Models\NawrisParcelOrder;
use App\Domain\Carrier\Models\NawrisWebhookEvent;
use App\Domain\Delivery\Enums\FulfilmentType;
use App\Domain\Delivery\Models\City;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Editing, cancelling and re-sending a parcel, and the two queues that make quiet failures
 * visible.
 *
 * **Reading is a different permission from acting**, deliberately: seeing that a parcel is stuck
 * is not the same authority as re-lodging it or closing a delivery conflict.
 *
 * Arrange - Act - Assert throughout.
 */
class NawrisOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        config()->set('services.nawris.authentication_key', 'key');
        config()->set('services.nawris.main_client_code', 'client');
        config()->set('services.nawris.base_url', 'https://carrier.test/external-api/');
        config()->set('services.nawris.log_channel', 'null');
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
     * @return array{Order, NawrisParcel}
     */
    private function dispatched(string $paid = '0.00'): array
    {
        $city = City::factory()->create([
            'fulfilment_type' => FulfilmentType::Delivery,
            'nawris_government_id' => '5',
            'delivery_price' => '20.00',
        ]);

        $order = Order::factory()->create([
            'city_id' => $city->id,
            'city_name' => $city->name,
            'fulfilment_type' => FulfilmentType::Delivery,
            'items_total' => '100.00',
            'delivery_price' => '20.00',
            'grand_total' => '100.00',
            'paid_amount' => $paid,
        ]);

        $parcel = NawrisParcel::factory()->create([
            'amount_to_collect' => '100.00',
            // Nothing is taken off the COD for delivery any more — the fee is in no total of
            // ours to take off. See `BuildNawrisPayload::amountToCollect()`.
            'delivery_price_deducted' => '0.00',
            'government' => '5',
            'area' => '204',
        ]);

        NawrisParcelOrder::factory()->create([
            'nawris_parcel_id' => $parcel->id,
            'order_id' => $order->id,
            'amount_to_collect' => '100.00',
        ]);

        return [$order, $parcel];
    }

    // ── editing ──────────────────────────────────────────────────────────────────────────

    public function test_a_payment_after_dispatch_lowers_the_cod_nawris_is_holding(): void
    {
        // Arrange — the contract's first field rule: a COD that still asks for a deposit the
        // customer already paid bills them twice.
        Http::fake(['*' => Http::response(['success' => 1, 'result' => []], 200)]);
        [$order, $parcel] = $this->dispatched(paid: '50.00');

        // Act
        app(CarrierService::class)->syncMoneyFor($order);

        // Assert — 100 − 50 paid.
        Http::assertSent(fn ($request) => (float) $request->data()['amount_to_be_collected'] === 50.0);
        $this->assertSame('50.00', (string) $parcel->fresh()->amount_to_collect);
    }

    public function test_an_edit_replays_the_frozen_destination_rather_than_re_deriving_it(): void
    {
        // Arrange — an edit carrying a different area *moves the parcel*, and re-reading the city
        // at edit time is how that happens by accident.
        Http::fake(['*' => Http::response(['success' => 1, 'result' => []], 200)]);
        [$order, $parcel] = $this->dispatched();
        $parcel->forceFill(['government' => '77', 'area' => '999'])->save();

        // Act
        app(CarrierService::class)->syncMoneyFor($order);

        // Assert
        Http::assertSent(fn ($request) => $request->data()['government'] === '77'
            && $request->data()['area'] === '999');
    }

    public function test_an_edit_keeps_the_reference_and_names_the_code(): void
    {
        // Arrange — changing the reference detaches the shipment from this record.
        Http::fake(['*' => Http::response(['success' => 1, 'result' => []], 200)]);
        [$order, $parcel] = $this->dispatched();

        // Act
        app(CarrierService::class)->syncMoneyFor($order);

        // Assert
        Http::assertSent(fn ($request) => $request->data()['remote_order_id'] === $parcel->reference
            && $request->data()['code'] === $parcel->code);
    }

    public function test_an_order_with_nothing_out_is_not_an_error(): void
    {
        // Arrange — the payment path must not have to know whether this order is on a van.
        Http::fake();
        $order = Order::factory()->create();

        // Act
        $result = app(CarrierService::class)->syncMoneyFor($order);

        // Assert
        $this->assertNull($result);
        Http::assertNothingSent();
    }

    // ── cancelling ───────────────────────────────────────────────────────────────────────

    public function test_calling_off_a_shipment_does_not_cancel_the_order(): void
    {
        // Arrange — «إلغاء تام» is unreachable while the parcel is outside the building. The goods
        // still have to come home.
        Http::fake(['*' => Http::response(['success' => 1], 200)]);
        [$order, $parcel] = $this->dispatched();
        $before = $order->status;

        // Act
        app(CarrierService::class)->cancelShipmentFor($order);

        // Assert
        $this->assertSame($before, $order->fresh()->status);
        $this->assertTrue($parcel->fresh()->isOpen());
    }

    // ── re-sending ───────────────────────────────────────────────────────────────────────

    // ── getting an order free again ──────────────────────────────────────────────────────

    public function test_deleting_a_shipment_tells_nawris_and_frees_the_order(): void
    {
        // Arrange — the ordinary "wrong parcel, start over" case, while it is still theirs to
        // delete.
        Http::fake(['*' => Http::response(['success' => 1, 'result' => []], 200)]);
        [$order, $parcel] = $this->dispatched();

        // Act
        $response = $this->withHeaders($this->auth(PermissionName::ManageCarrierParcels))
            ->postJson("/api/v1/carrier/orders/{$order->id}/delete-shipment");

        // Assert — closed, so the "one open parcel" guard lets the order go out again.
        $response->assertOk();
        $this->assertNotNull($parcel->fresh()->closed_at);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'delete-order'));
    }

    public function test_unlinking_never_speaks_to_nawris(): void
    {
        // Arrange — the case this exists for: somebody deleted the parcel in *their* portal, so
        // asking them to delete it again would only earn an error about a parcel that is gone.
        Http::fake();
        [$order] = $this->dispatched();

        // Act
        $response = $this->withHeaders($this->auth(PermissionName::ManageCarrierParcels))
            ->postJson("/api/v1/carrier/orders/{$order->id}/unlink");

        // Assert
        $response->assertOk();
        Http::assertNothingSent();
    }

    public function test_unlinking_lets_the_order_be_sent_again(): void
    {
        // Arrange
        Http::fake();
        [$order] = $this->dispatched();

        // Act
        $this->withHeaders($this->auth(PermissionName::ManageCarrierParcels))
            ->postJson("/api/v1/carrier/orders/{$order->id}/unlink");

        // Assert — the guard reads the link rows, and this one is gone from them.
        $this->assertNull(app(CarrierService::class)->openParcelFor($order->fresh()));
    }

    public function test_unlinking_keeps_the_link_as_history(): void
    {
        // Arrange — a parcel that was really lodged is a thing that really happened, and the
        // reference we minted for it is how their support finds it.
        Http::fake();
        [$order, $parcel] = $this->dispatched();

        // Act
        $this->withHeaders($this->auth(PermissionName::ManageCarrierParcels))
            ->postJson("/api/v1/carrier/orders/{$order->id}/unlink");

        // Assert — soft-deleted, not destroyed.
        $this->assertDatabaseHas('nawris_parcel_orders', ['order_id' => $order->id]);
        $this->assertSoftDeleted('nawris_parcel_orders', ['order_id' => $order->id]);
        $this->assertNotNull(NawrisParcel::withTrashed()->find($parcel->id));
    }

    public function test_an_order_with_nothing_out_is_told_so_rather_than_refused(): void
    {
        // Arrange — pressing either of these twice is a person checking, not an error.
        Http::fake();
        $order = Order::factory()->create();

        // Act
        $delete = $this->withHeaders($this->auth(PermissionName::ManageCarrierParcels))
            ->postJson("/api/v1/carrier/orders/{$order->id}/delete-shipment");
        $unlink = $this->withHeaders($this->auth(PermissionName::ManageCarrierParcels))
            ->postJson("/api/v1/carrier/orders/{$order->id}/unlink");

        // Assert
        $delete->assertOk();
        $unlink->assertOk();
        Http::assertNothingSent();
    }

    public function test_neither_is_open_to_the_view_permission_alone(): void
    {
        // Arrange — both throw away a hand-over; reading that one exists is not that authority.
        Http::fake();
        [$order] = $this->dispatched();
        $headers = $this->auth(PermissionName::ViewCarrierParcels);

        // Act
        $delete = $this->withHeaders($headers)->postJson("/api/v1/carrier/orders/{$order->id}/delete-shipment");
        $unlink = $this->withHeaders($headers)->postJson("/api/v1/carrier/orders/{$order->id}/unlink");

        // Assert
        $delete->assertForbidden();
        $unlink->assertForbidden();
    }

    public function test_a_resend_closes_the_old_parcel_and_opens_a_new_one(): void
    {
        // Arrange — a second journey is a second parcel row, which is what keeps the first one's
        // history.
        Http::fake(['*' => Http::response([
            'success' => 1,
            'result' => ['code' => '3702994N', 'bar_code' => 'B-2'],
        ], 200)]);
        [$order, $parcel] = $this->dispatched();

        // Act
        $fresh = app(CarrierService::class)->resendFor($order);

        // Assert
        $this->assertNotNull($fresh);
        $this->assertNotSame($parcel->id, $fresh->id);
        $this->assertNotNull($parcel->fresh()->closed_at);
        $this->assertSame('3702994N', $fresh->code);
        $this->assertNotSame($parcel->reference, $fresh->reference);
        $this->assertSame('5', $fresh->government);
    }

    public function test_a_resend_sends_their_documented_fields_and_no_others(): void
    {
        // Arrange — their published table: `code` and `type` are mandatory, and `order_code` is
        // «كود الطرد الجديد» — a *parcel* code, wanted only when `type = 2`. The first version of
        // this sent our order's code in it on every call, which is a different thing entirely.
        Http::fake(['*' => Http::response([
            'success' => 1,
            'result' => ['code' => '3702994N'],
        ], 200)]);
        [$order] = $this->dispatched();

        // Act
        app(CarrierService::class)->resendFor($order);

        // Assert
        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), 'resend-request')
                && ($body['type'] ?? null) === '1'
                && ! array_key_exists('order_code', $body);
        });
    }

    public function test_a_resend_is_reachable_over_http(): void
    {
        // Arrange
        Http::fake(['*' => Http::response([
            'success' => 1,
            'result' => ['code' => '3702994N'],
        ], 200)]);
        [$order] = $this->dispatched();

        // Act
        $response = $this->withHeaders($this->auth(PermissionName::ManageCarrierParcels))
            ->postJson("/api/v1/carrier/orders/{$order->id}/resend");

        // Assert — 201: a re-send makes a parcel, exactly as lodging one does.
        $response->assertCreated();
    }

    public function test_a_resend_needs_more_than_the_view_permission(): void
    {
        // Arrange — sending goods out a second time is an act, not a reading.
        Http::fake();
        [$order] = $this->dispatched();

        // Act
        $response = $this->withHeaders($this->auth(PermissionName::ViewCarrierParcels))
            ->postJson("/api/v1/carrier/orders/{$order->id}/resend");

        // Assert
        $response->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_a_resend_with_nothing_out_is_told_so(): void
    {
        // Arrange — the same courtesy the other two releases extend.
        Http::fake();
        $order = Order::factory()->create();

        // Act
        $response = $this->withHeaders($this->auth(PermissionName::ManageCarrierParcels))
            ->postJson("/api/v1/carrier/orders/{$order->id}/resend");

        // Assert
        $response->assertOk();
        Http::assertNothingSent();
    }

    // ── the queues ───────────────────────────────────────────────────────────────────────

    public function test_the_events_list_can_be_narrowed_to_the_unprocessed(): void
    {
        // Arrange — received and never processed is the failure that otherwise goes unnoticed for
        // weeks.
        NawrisWebhookEvent::factory()->processed()->create();
        $pending = NawrisWebhookEvent::factory()->create();
        $headers = $this->auth(PermissionName::ViewCarrierParcels);

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/carrier/events?pending=1');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.is_pending', true);
    }

    public function test_the_events_list_can_be_narrowed_to_the_unmatched(): void
    {
        // Arrange
        $parcel = NawrisParcel::factory()->create();
        NawrisWebhookEvent::factory()->processed()->create(['nawris_parcel_id' => $parcel->id]);
        $orphan = NawrisWebhookEvent::factory()->processed()->create(['nawris_parcel_id' => null]);
        $headers = $this->auth(PermissionName::ViewCarrierParcels);

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/carrier/events?unmatched=1');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $orphan->id);
    }

    public function test_parcels_can_be_narrowed_to_those_waiting_on_a_human(): void
    {
        // Arrange
        NawrisParcel::factory()->create();
        $conflicted = NawrisParcel::factory()->create(['conflict_raised_at' => now()]);
        $headers = $this->auth(PermissionName::ViewCarrierParcels);

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/carrier/parcels?conflict=1');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $conflicted->id)
            ->assertJsonPath('data.0.has_open_conflict', true);
    }

    public function test_a_conflict_can_be_closed_by_a_person(): void
    {
        // Arrange — raised automatically, cleared automatically by a clean delivery, and this is
        // the third way out.
        $parcel = NawrisParcel::factory()->create(['conflict_raised_at' => now()]);
        $headers = $this->auth(PermissionName::ManageCarrierParcels);

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/carrier/parcels/{$parcel->id}/resolve-conflict");

        // Assert
        $response->assertOk()->assertJsonPath('data.has_open_conflict', false);
        $this->assertNotNull($parcel->fresh()->conflict_resolved_at);
    }

    // ── authorization ────────────────────────────────────────────────────────────────────

    public function test_reading_the_queues_needs_the_view_permission(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/carrier/events');

        // Assert
        $response->assertForbidden();
    }

    public function test_closing_a_conflict_needs_more_than_the_view_permission(): void
    {
        // Arrange — seeing that a parcel is stuck is not the authority to declare it fine.
        $parcel = NawrisParcel::factory()->create(['conflict_raised_at' => now()]);
        $headers = $this->auth(PermissionName::ViewCarrierParcels);

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/carrier/parcels/{$parcel->id}/resolve-conflict");

        // Assert
        $response->assertForbidden();
        $this->assertTrue($parcel->fresh()->hasOpenConflict());
    }

    public function test_the_queues_are_closed_to_anonymous_callers(): void
    {
        // Act
        $response = $this->getJson('/api/v1/carrier/events');

        // Assert
        $response->assertUnauthorized();
    }
}
