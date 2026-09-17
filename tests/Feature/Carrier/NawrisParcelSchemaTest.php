<?php

declare(strict_types=1);

namespace Tests\Feature\Carrier;

use App\Domain\Carrier\Models\NawrisParcel;
use App\Domain\Carrier\Models\NawrisParcelOrder;
use App\Domain\Carrier\Models\NawrisWebhookEvent;
use App\Domain\Order\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three tables the carrier integration keeps, and the guarantees the database itself makes.
 *
 * **These are assertions about constraints, not about behaviour.** Every rule below is one a
 * concurrent write could break if it lived only in PHP — a second parcel claiming a code, a
 * duplicate webhook slipping past a status comparison — so each is enforced by an index and
 * checked here against the database rather than against an action.
 *
 * Arrange - Act - Assert throughout.
 */
class NawrisParcelSchemaTest extends TestCase
{
    use RefreshDatabase;

    // ── the parcel ───────────────────────────────────────────────────────────────────────

    public function test_two_parcels_cannot_share_a_code(): void
    {
        // Arrange
        NawrisParcel::factory()->create(['code' => 'X-1']);

        // Assert
        $this->expectException(QueryException::class);

        // Act
        NawrisParcel::factory()->create(['code' => 'X-1']);
    }

    public function test_two_parcels_cannot_share_a_reference(): void
    {
        // Arrange — the reference is what a webhook matches on first. Two parcels sharing one
        // would make that lookup ambiguous exactly when money is moving.
        NawrisParcel::factory()->create(['reference' => 'r-1']);

        // Assert
        $this->expectException(QueryException::class);

        // Act
        NawrisParcel::factory()->create(['reference' => 'r-1']);
    }

    public function test_a_deleted_parcel_releases_its_code_and_reference(): void
    {
        // Arrange — every unique index here is partial, so a parcel removed by mistake does not
        // hold its identifiers hostage forever.
        $first = NawrisParcel::factory()->create(['code' => 'X-2', 'reference' => 'r-2']);

        // Act
        $first->delete();
        $second = NawrisParcel::factory()->create(['code' => 'X-2', 'reference' => 'r-2']);

        // Assert
        $this->assertNotSame($first->id, $second->id);
        $this->assertSoftDeleted($first);
    }

    public function test_a_parcel_is_open_until_it_is_closed(): void
    {
        // Arrange & Act
        $open = NawrisParcel::factory()->create();
        $closed = NawrisParcel::factory()->closed()->create();

        // Assert — `closed_at` is what makes "still out there" a query rather than a status list.
        $this->assertTrue($open->isOpen());
        $this->assertFalse($closed->isOpen());
    }

    public function test_a_conflict_is_open_until_it_is_resolved(): void
    {
        // Arrange & Act
        $raised = NawrisParcel::factory()->create(['conflict_raised_at' => now()]);
        $resolved = NawrisParcel::factory()->create([
            'conflict_raised_at' => now()->subHour(),
            'conflict_resolved_at' => now(),
        ]);

        // Assert
        $this->assertTrue($raised->hasOpenConflict());
        $this->assertFalse($resolved->hasOpenConflict());
        $this->assertFalse(NawrisParcel::factory()->create()->hasOpenConflict());
    }

    // ── the link ─────────────────────────────────────────────────────────────────────────

    public function test_an_order_cannot_be_linked_to_the_same_parcel_twice(): void
    {
        // Arrange
        $parcel = NawrisParcel::factory()->create();
        $order = Order::factory()->create();
        NawrisParcelOrder::factory()->create([
            'nawris_parcel_id' => $parcel->id,
            'order_id' => $order->id,
        ]);

        // Assert
        $this->expectException(QueryException::class);

        // Act
        NawrisParcelOrder::factory()->create([
            'nawris_parcel_id' => $parcel->id,
            'order_id' => $order->id,
        ]);
    }

    public function test_an_order_may_belong_to_more_than_one_parcel_over_its_life(): void
    {
        // Arrange — the key is (parcel, order), never order alone. A re-dispatch writes a second
        // row instead of deleting the first, so the dispatch history survives.
        $order = Order::factory()->create();
        $first = NawrisParcel::factory()->closed()->create();
        $second = NawrisParcel::factory()->create();

        // Act
        NawrisParcelOrder::factory()->create(['nawris_parcel_id' => $first->id, 'order_id' => $order->id]);
        NawrisParcelOrder::factory()->create(['nawris_parcel_id' => $second->id, 'order_id' => $order->id]);

        // Assert
        $this->assertSame(2, NawrisParcelOrder::query()->where('order_id', $order->id)->count());
    }

    public function test_a_parcel_reaches_its_orders(): void
    {
        // Arrange
        $parcel = NawrisParcel::factory()->create();
        $order = Order::factory()->create();
        NawrisParcelOrder::factory()->create([
            'nawris_parcel_id' => $parcel->id,
            'order_id' => $order->id,
            'amount_to_collect' => '80.00',
        ]);

        // Act
        $orders = $parcel->fresh()->orders;

        // Assert — this is the relation "rebuild the whole parcel" is built on.
        $this->assertCount(1, $orders);
        $this->assertSame($order->id, $orders->first()->id);
        $this->assertSame('80.00', $orders->first()->pivot->amount_to_collect);
    }

    // ── the event log ────────────────────────────────────────────────────────────────────

    public function test_a_duplicate_webhook_is_refused_by_the_database(): void
    {
        // Arrange — duplicate suppression is a constraint, not a comparison. A status comparison
        // in PHP can be silently disabled by a type mismatch; a unique index cannot.
        NawrisWebhookEvent::factory()->create(['fingerprint' => 'abc']);

        // Assert
        $this->expectException(QueryException::class);

        // Act
        NawrisWebhookEvent::factory()->create(['fingerprint' => 'abc']);
    }

    public function test_an_event_that_matched_nothing_is_still_stored(): void
    {
        // Arrange & Act — the nullable parcel is the whole point: an unmatched webhook gets a row
        // that can be found and replayed, rather than a log line nobody reads.
        $event = NawrisWebhookEvent::factory()->create(['nawris_parcel_id' => null, 'code' => 'ghost']);

        // Assert
        $this->assertTrue($event->isUnmatched());
        $this->assertTrue($event->isPending());
        $this->assertDatabaseHas('nawris_webhook_events', ['code' => 'ghost', 'nawris_parcel_id' => null]);
    }

    public function test_the_payload_is_kept_verbatim(): void
    {
        // Arrange — they do not re-send. Anything not stored is gone permanently, so the body is
        // written before anything is interpreted from it.
        $body = ['order_code' => '3702994', 'to_status_code' => 7, 'to_status_text' => 'تم التسليم'];

        // Act
        $event = NawrisWebhookEvent::factory()->create(['payload' => $body]);

        // Assert
        $this->assertSame($body, $event->fresh()->payload);
    }

    public function test_an_event_points_at_its_parcel_when_it_matched_one(): void
    {
        // Arrange
        $parcel = NawrisParcel::factory()->create();

        // Act
        $event = NawrisWebhookEvent::factory()->processed()->create(['nawris_parcel_id' => $parcel->id]);

        // Assert
        $this->assertFalse($event->isUnmatched());
        $this->assertFalse($event->isPending());
        $this->assertSame($parcel->id, $event->parcel->id);
    }
}
