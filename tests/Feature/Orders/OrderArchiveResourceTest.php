<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Application\Api\V1\Resources\OrderResource;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * What an archived order's payload is allowed to *offer*, as opposed to what it says.
 *
 * `OrderArchiveTest` guards the archive as a screen — which rows it lists, who may read them,
 * which routes stay 404. This file guards the one thing that file left half-done: §٦ says an
 * archived order is offered **no move**, and the first cut applied that rule key by key. Two keys
 * were written after the gate and never passed through it, and both are the same failure — a
 * button drawn from a key whose write route answers 404:
 *
 * - `reinstate_to`/`reinstate_to_label`: an archived «إلغاء تام» satisfies every condition
 *   {@see OrderResource::reinstatableToFor()} asks about —
 *   cancelled, granted, timeline loaded, because the show route reads `->withTrashed()` and
 *   `loadForDisplay()` eager-loads `transitions` — so «تراجع عن الإلغاء» was drawn live over a
 *   `POST /orders/{order}/reinstate` that is deliberately not `->withTrashed()`.
 * - `items_are_editable` and the two lines beside it: read from the status alone, so an archived
 *   order parked at «قيد الطباعة» published `true` and the app opened an editor whose PATCH 404s.
 *
 * So the assertion this file really makes is the *set*: everything the live screen offers to act
 * with, and nothing of it on the archived one. Asserted as one difference rather than as a key
 * at a time, because a key at a time is precisely how the two above were missed.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderArchiveResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every key the payload offers to *act* with, as opposed to the facts it states.
     *
     * The list is here so that the difference below can be asserted in both directions: a key
     * that stops being withheld fails, and a key added to the group without being named here
     * fails too. Editing it is a decision somebody makes on purpose — the shape
     * `order_resource_contract_test.dart` uses for its own allowlist, for the same reason.
     */
    private const OFFERED_KEYS = [
        'available_transitions',
        'designs_are_editable',
        'destination_is_editable',
        'items_are_editable',
        'progress',
        'reinstate_to',
        'reinstate_to_label',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    /**
     * Somebody who may read the archive and undo a cancellation — the reader who saw the dead
     * button, because a reader without `orders.cancel` was never offered it in the first place.
     *
     * @return array<string, string>
     */
    private function archivist(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewOrders->value,
            PermissionName::ViewOrderArchive->value,
            PermissionName::CancelOrders->value,
            PermissionName::MoveOrderToDesigning->value,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * An order standing in «إلغاء تام» with the timeline row that says where it came from.
     *
     * Cancelled through the endpoint rather than by writing the status on the model: the
     * destination of «تراجع عن الإلغاء» is read from `order_status_transitions`, and an order
     * whose status was set by hand carries no such row — it would answer null for the honest
     * reason instead of the one under test, and the test would pass while the bug stood.
     */
    private function cancelledOrder(array $headers): Order
    {
        // «قيد التصميم» rather than «جديدة»: cancelling is refused straight out of «جديدة» — a
        // brand-new order is closed by being deleted, which is this whole feature — and the
        // first status that offers it without a warehouse handover in the way is this one.
        $order = Order::factory()->status(OrderStatus::Designing)->create();

        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Cancelled->value,
            'reason' => 'إدخال مكرر',
        ])->assertOk();

        return $order->refresh();
    }

    public function test_an_archived_cancellation_offers_no_way_out_of_itself(): void
    {
        // Arrange
        $headers = $this->archivist();
        $order = $this->cancelledOrder($headers);
        $order->delete();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert — absent, not null: `POST /orders/{order}/reinstate` resolves live orders only,
        // so a button drawn from this key is a button that answers 404.
        $response->assertOk()
            ->assertJsonMissingPath('data.reinstate_to')
            ->assertJsonMissingPath('data.reinstate_to_label');
    }

    public function test_a_live_cancellation_still_names_where_the_undo_lands(): void
    {
        // Arrange — the half of the rule that must not be over-applied: withholding the undo from
        // every cancelled order would take the only way out of «إلغاء تام» off the screen.
        $headers = $this->archivist();
        $order = $this->cancelledOrder($headers);

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.reinstate_to', OrderStatus::Designing->value)
            ->assertJsonPath('data.reinstate_to_label', OrderStatus::Designing->label());
    }

    public function test_an_archived_order_claims_nothing_about_what_may_still_be_edited(): void
    {
        // Arrange — «قيد الطباعة» is the status that makes the bug visible: the lines *are*
        // editable there, so the flag published true on a row every write route refuses.
        $headers = $this->archivist();
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        $order->delete();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert
        $response->assertOk()
            ->assertJsonMissingPath('data.items_are_editable')
            ->assertJsonMissingPath('data.designs_are_editable')
            ->assertJsonMissingPath('data.destination_is_editable');
    }

    public function test_a_live_order_at_the_press_still_says_its_lines_may_be_corrected(): void
    {
        // Arrange
        $headers = $this->archivist();
        $order = Order::factory()->status(OrderStatus::Printing)->create();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.items_are_editable', true)
            ->assertJsonPath('data.destination_is_editable', true);
    }

    public function test_the_archived_payload_differs_from_the_live_one_by_exactly_the_offers(): void
    {
        // Arrange — two orders of the same shape and status, one of them archived, so the only
        // thing that can separate their payloads is the archive itself
        $headers = $this->archivist();
        $live = $this->cancelledOrder($headers);
        $archived = $this->cancelledOrder($headers);
        $archived->delete();

        // Act
        $liveKeys = array_keys(
            $this->withHeaders($headers)->getJson("/api/v1/orders/{$live->id}")->json('data'),
        );
        $archivedKeys = array_keys(
            $this->withHeaders($headers)->getJson("/api/v1/orders/{$archived->id}")->json('data'),
        );

        // Assert — asserted as one set rather than a key at a time, because a key at a time is
        // how `reinstate_to` came to be published on a row that cannot be reinstated.
        $withheld = array_values(array_diff($liveKeys, $archivedKeys));
        sort($withheld);

        $this->assertSame(self::OFFERED_KEYS, $withheld);
    }
}
