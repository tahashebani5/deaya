<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Taking the delivery fee back out of the totals it was already added to.
 *
 * The fee left `grand_total` on 2026-09-08 by the owner's instruction. Orders taken before that
 * are still carrying it, and an order in the middle of its life is the one that cannot be left
 * that way: `BuildNawrisPayload` no longer subtracts the fee, so the next edit of an in-flight
 * parcel would ask the courier to collect it *and* let the courier charge it at the door.
 *
 * Closed orders are a different question and this command refuses them on purpose — see the
 * class docblock.
 *
 * Arrange - Act - Assert throughout.
 */
class DropDeliveryFromOrderTotalsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An order carrying the fee inside its total, the way every order taken before the change is.
     */
    private function order(
        OrderStatus $status = OrderStatus::Ready,
        string $delivery = '20.00',
        string $paid = '0.00',
        string $discount = '0.00',
    ): Order {
        $order = Order::factory()->status($status)->create([
            'delivery_price' => $delivery,
            'items_total' => '100.00',
            'discount' => $discount,
            // The old arithmetic, written onto the row exactly as it stood.
            'grand_total' => bcsub(bcadd('100.00', $delivery, 2), $discount, 2),
            'paid_amount' => $paid,
        ]);

        OrderItem::factory()->for($order)->create([
            'quantity' => '100.000',
            'unit_price' => '1.000',
            'line_total' => '100.00',
        ]);

        return $order->refresh();
    }

    public function test_an_open_order_loses_the_fee_from_its_total(): void
    {
        // Arrange
        $order = $this->order();

        // Act
        $this->artisan('orders:drop-delivery-from-totals', ['--force' => true])->assertSuccessful();

        // Assert — the goods alone, and the fee still on the row for the clerk to quote.
        $this->assertSame('100.00', (string) $order->fresh()->grand_total);
        $this->assertSame('20.00', (string) $order->fresh()->delivery_price);
    }

    public function test_a_closed_order_is_left_exactly_as_it_stands(): void
    {
        // Arrange — the books say we billed 120 and collected it. That happened, and a repair
        // that rewrites it makes the record disagree with the receipt in the customer's hand.
        $order = $this->order(OrderStatus::Delivered, paid: '120.00');

        // Act
        $this->artisan('orders:drop-delivery-from-totals', ['--force' => true])->assertSuccessful();

        // Assert
        $this->assertSame('120.00', (string) $order->fresh()->grand_total);
    }

    public function test_a_cancelled_order_is_left_alone_too(): void
    {
        // Arrange — nothing is owed on it and nothing will be collected, so there is no figure
        // here worth moving.
        $order = $this->order(OrderStatus::Cancelled);

        // Act
        $this->artisan('orders:drop-delivery-from-totals', ['--force' => true])->assertSuccessful();

        // Assert
        $this->assertSame('120.00', (string) $order->fresh()->grand_total);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        // Arrange
        $order = $this->order();

        // Act
        $this->artisan('orders:drop-delivery-from-totals', ['--dry-run' => true])->assertSuccessful();

        // Assert — the whole point of running it against a live database first.
        $this->assertSame('120.00', (string) $order->fresh()->grand_total);
    }

    public function test_an_order_whose_deposit_now_exceeds_its_total_is_named_rather_than_skipped(): void
    {
        // Arrange — 110 taken against a bill of 120, of which 20 was the trip. Without the fee
        // the order is worth 100, and the customer has handed us 10 more than they owe *and*
        // will be charged the trip again at the door.
        $order = $this->order(paid: '110.00');

        // Act — chained rather than held in a variable: a `PendingCommand` runs when it is
        // destroyed, so a named one would run *after* the assertions below it.
        $this->artisan('orders:drop-delivery-from-totals', ['--force' => true])
            ->expectsOutputToContain((string) $order->code)
            ->assertSuccessful();

        // Assert — corrected, and reported: a person has to decide whether that 10 goes back.
        $this->assertSame('100.00', (string) $order->fresh()->grand_total);
        $this->assertSame('-10.00', $order->fresh()->remainingAmount());
    }

    public function test_a_discount_that_no_longer_fits_is_refused_rather_than_forced(): void
    {
        // Arrange — 115 off a bill of 120 was allowed when the trip was part of it. Against 100
        // it is not a discount any more, and silently shrinking it would charge the customer
        // more than the clerk promised them.
        $order = $this->order(discount: '115.00');

        // Act
        $this->artisan('orders:drop-delivery-from-totals', ['--force' => true])
            ->expectsOutputToContain((string) $order->code)
            ->assertSuccessful();

        // Assert — left exactly as it was, and named, because only a person can re-agree it.
        $this->assertSame('5.00', (string) $order->fresh()->grand_total);
        $this->assertSame('115.00', (string) $order->fresh()->discount);
    }

    public function test_running_it_a_second_time_changes_nothing(): void
    {
        // Arrange
        $order = $this->order();
        $this->artisan('orders:drop-delivery-from-totals', ['--force' => true])->assertSuccessful();
        $touched = $order->fresh()->updated_at;

        // Act
        $this->artisan('orders:drop-delivery-from-totals', ['--force' => true])->assertSuccessful();

        // Assert — a row that needs nothing is not written to, so `updated_at` still says when a
        // person last changed the order rather than when a cleanup last ran over it.
        $this->assertSame('100.00', (string) $order->fresh()->grand_total);
        $this->assertEquals($touched, $order->fresh()->updated_at);
    }
}
