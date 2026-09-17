<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Identity\Models\User;
use App\Domain\Order\Actions\RecordCarrierSettlement;
use App\Domain\Order\Actions\RecordOrderPayment;
use App\Domain\Order\Actions\ReverseOrderPayment;
use App\Domain\Order\DTOs\OrderPaymentData;
use App\Domain\Order\Enums\OrderPaymentType;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Enums\PaymentStatus;
use App\Domain\Order\Exceptions\CarrierSettlementExceedsRemaining;
use App\Domain\Order\Exceptions\PaymentAmountMustBePositive;
use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The delivery fee the customer paid to the courier instead of to us.
 *
 * **This entry exists because of the Nawris integration and nothing else** — see
 * NAWRIS-INTEGRATION.md §5.2. We subtract our `delivery_price` from the COD before handing the
 * parcel over, so the carrier collects it at the door on their own account. That leaves the order
 * owing exactly `delivery_price` that no cash will ever close, and «تم التسوية» refuses a debt.
 *
 * **It is the third kind of closing, and it is not either of the first two.** Recording it as a
 * payment would put money in the drawer report that never reached the drawer; recording it as a
 * write-off would post a loss for every delivery, when nothing was lost — the customer paid in
 * full, just not all of it to us.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderCarrierSettlementTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $grandTotal = '120.00'): Order
    {
        return Order::factory()->create([
            'items_total' => '100.00',
            'delivery_price' => '20.00',
            'grand_total' => $grandTotal,
        ]);
    }

    private function settle(): RecordCarrierSettlement
    {
        return app(RecordCarrierSettlement::class);
    }

    // ── what it does to the order ────────────────────────────────────────────────────────

    public function test_it_credits_the_order_without_ever_touching_cash(): void
    {
        // Arrange
        $order = $this->order();

        // Act
        ($this->settle())($order, '20.00', 'أجرة التوصيل محصّلة لدى الناقل');

        // Assert — the whole point: the debt shrinks, «كم قبضنا؟» does not move.
        $order->refresh();
        $this->assertSame('20.00', (string) $order->carrier_settled_amount);
        $this->assertSame('0.00', (string) $order->paid_amount);
        $this->assertSame('0.00', (string) $order->written_off_amount);
        $this->assertSame('100.00', $order->remainingAmount());
    }

    public function test_cash_and_a_carrier_settlement_together_close_the_order(): void
    {
        // Arrange — 120 owed: 100 collected by the courier and remitted, 20 taken at the door.
        $order = $this->order();
        $actor = User::factory()->create();

        // Act
        app(RecordOrderPayment::class)($order, OrderPaymentData::fromArray([
            'amount' => '100.00',
            'method' => PaymentMethod::Cash->value,
        ]), $actor);

        ($this->settle())($order->refresh(), '20.00', 'أجرة التوصيل محصّلة لدى الناقل');

        // Assert
        $order->refresh();
        $this->assertSame('0.00', $order->remainingAmount());
        $this->assertSame(PaymentStatus::Paid, $order->paymentStatus());
        $this->assertFalse($order->paymentStatus()->isOutstanding());
    }

    public function test_an_order_closed_this_way_does_not_read_as_written_off(): void
    {
        // Arrange — the distinction the third bucket exists for. Nothing was forgiven: the
        // customer paid every dinar, and «مشطوب فرقها» would call a completed sale a loss.
        $order = $this->order();
        $actor = User::factory()->create();

        // Act
        app(RecordOrderPayment::class)($order, OrderPaymentData::fromArray([
            'amount' => '100.00',
            'method' => PaymentMethod::Cash->value,
        ]), $actor);
        ($this->settle())($order->refresh(), '20.00', 'أجرة التوصيل');

        // Assert
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->paymentStatus());
        $this->assertNotSame(PaymentStatus::WrittenOff, $order->paymentStatus());
    }

    // ── the entry itself ─────────────────────────────────────────────────────────────────

    public function test_the_entry_names_no_method_and_the_table_accepts_it(): void
    {
        // Arrange — the `order_payments_shape` CHECK had to be widened for this: the old rule
        // said everything that is not a reversal or a write-off names a method.
        $order = $this->order();

        // Act
        $entry = ($this->settle())($order, '20.00', 'أجرة التوصيل');

        // Assert
        $this->assertSame(OrderPaymentType::CarrierSettled, $entry->type);
        $this->assertNull($entry->method);
        $this->assertNull($entry->reverses_payment_id);
        $this->assertDatabaseHas('order_payments', [
            'id' => $entry->id,
            'type' => OrderPaymentType::CarrierSettled->value,
            'method' => null,
        ]);
    }

    public function test_the_type_answers_the_three_questions_the_ledger_asks_of_it(): void
    {
        // Arrange & Act
        $type = OrderPaymentType::CarrierSettled;

        // Assert — a credit, but not cash and not a method. `movedCash()` false is what keeps
        // this out of the drawer report.
        $this->assertTrue($type->isCredit());
        $this->assertFalse($type->movedCash());
        $this->assertFalse($type->namesAMethod());
        $this->assertFalse($type->isWriteOff());
    }

    // ── the guards ───────────────────────────────────────────────────────────────────────

    public function test_it_refuses_more_than_the_order_still_owes(): void
    {
        // Arrange — the same ceiling every other credit entry has. Without it a repeated
        // webhook could close an order twice over.
        $order = $this->order();

        // Act & Assert
        $this->expectException(CarrierSettlementExceedsRemaining::class);
        ($this->settle())($order, '200.00', 'أجرة التوصيل');
    }

    public function test_it_refuses_a_zero_or_negative_amount(): void
    {
        // Arrange
        $order = $this->order();

        // Act & Assert
        $this->expectException(PaymentAmountMustBePositive::class);
        ($this->settle())($order, '0.00', 'أجرة التوصيل');
    }

    // ── undoing one ──────────────────────────────────────────────────────────────────────

    public function test_reversing_it_puts_the_debt_back_where_it_was(): void
    {
        // Arrange — a delivery recorded in error is undone the ordinary way, without either a
        // discount or a write-off.
        $order = $this->order();
        $actor = User::factory()->create();
        $entry = ($this->settle())($order, '20.00', 'أجرة التوصيل');

        // Act
        app(ReverseOrderPayment::class)($order->refresh(), $entry, 'سُجِّلت على الطلبية الخطأ', $actor);

        // Assert — back off the carrier total, never off cash.
        $order->refresh();
        $this->assertSame('0.00', (string) $order->carrier_settled_amount);
        $this->assertSame('0.00', (string) $order->paid_amount);
        $this->assertSame('120.00', $order->remainingAmount());
    }
}
