<?php

declare(strict_types=1);

namespace Tests\Feature\Carrier;

use App\Domain\Carrier\Models\NawrisParcel;
use App\Domain\Carrier\Models\NawrisParcelOrder;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * «كود النورس» on an order — on the card outside, and in the details inside.
 *
 * The code is the carrier's own handle on the parcel, and it is what is said out loud when a
 * customer rings about a delivery. It is **not** `tracking_number`, which is a box a person types
 * into: the two are published side by side and neither stands in for the other.
 *
 * The awkward part this file pins down is that `Order` may not know a carrier exists — the
 * back-reference is the cycle RULES §3 forbids — so the code arrives by a different road, and a
 * road that is only half built shows up as an order that has a parcel and no code on screen.
 *
 * Arrange - Act - Assert throughout.
 */
class NawrisCodeOnOrderTest extends TestCase
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
    private function clerk(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::ViewOrders->value);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** A parcel at the carrier, carrying this order. */
    private function parcel(Order $order, string $code, ?string $closedAt = null): NawrisParcel
    {
        $parcel = NawrisParcel::factory()->create([
            'code' => $code,
            'closed_at' => $closedAt,
        ]);

        NawrisParcelOrder::factory()->create([
            'nawris_parcel_id' => $parcel->getKey(),
            'order_id' => $order->getKey(),
        ]);

        return $parcel;
    }

    public function test_an_order_carries_the_carriers_code_on_the_list_and_in_its_details(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $this->parcel($order, '3702994');
        $headers = $this->clerk();

        // Act
        $list = $this->withHeaders($headers)->getJson('/api/v1/orders');
        $detail = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert — the same code from both doors. The card reads it off the list, so a screen
        // that had to open the order to learn the number would defeat the point of putting it
        // on the card at all.
        $list->assertOk()->assertJsonPath('data.0.nawris_parcel.code', '3702994');
        $detail->assertOk()->assertJsonPath('data.nawris_parcel.code', '3702994');

        // Still out there, because nothing closed it.
        $this->assertTrue($detail->json('data.nawris_parcel.is_open'));
    }

    public function test_an_order_that_never_went_to_the_carrier_has_no_key_at_all(): void
    {
        // Arrange — an ordinary order, never dispatched.
        Order::factory()->create();

        // Act
        $response = $this->withHeaders($this->clerk())->getJson('/api/v1/orders');

        // Assert — **absent, not null.** A client handed `"nawris_parcel": null` has been told
        // the question was asked and came back empty; a missing key says it was never asked, and
        // that is what stops a screen drawing «كود النورس: —» under every office pickup.
        $response->assertOk();
        $this->assertArrayNotHasKey('nawris_parcel', $response->json('data.0'));
    }

    public function test_a_resent_order_shows_the_code_on_the_label_in_the_couriers_hand(): void
    {
        // Arrange — it went out, came home, and went out again under a new code. Both links
        // stand: the dispatch history is never rewritten.
        $order = Order::factory()->create();
        $this->parcel($order, 'OLD-1', closedAt: now()->subDay()->toDateTimeString());
        $this->parcel($order, 'NEW-2');

        // Act
        $response = $this->withHeaders($this->clerk())->getJson("/api/v1/orders/{$order->id}");

        // Assert — the newest link, not the first. A customer ringing about this parcel is
        // holding a label that says NEW-2, and reading him the closed one sends him to a
        // shipment that came back weeks ago.
        $response->assertOk()->assertJsonPath('data.nawris_parcel.code', 'NEW-2');
        $this->assertTrue($response->json('data.nawris_parcel.is_open'));
    }

    public function test_a_parcel_still_waiting_for_its_code_is_not_published_as_an_empty_one(): void
    {
        // Arrange — the row exists between building it and the carrier answering.
        $order = Order::factory()->create();
        $this->parcel($order, code: 'REAL-1');
        NawrisParcel::query()->latest('id')->first()?->forceFill(['code' => null])->save();

        // Act
        $response = $this->withHeaders($this->clerk())->getJson("/api/v1/orders/{$order->id}");

        // Assert — no key, rather than a code that is nothing.
        $response->assertOk();
        $this->assertArrayNotHasKey('nawris_parcel', $response->json('data'));
    }
}
