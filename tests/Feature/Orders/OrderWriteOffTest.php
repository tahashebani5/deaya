<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderPaymentType;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Enums\PaymentStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Writing off the difference — the five dinars a customer never paid.
 *
 * **The case this whole file exists for.** An order of 110 comes back from the courier with 105
 * in the envelope, and the business decides the rest is not worth chasing. Before this, that
 * order could not move: «تم التسوية» is refused while anything is outstanding, and the invoice
 * itself is frozen once the customer has the bags, so there was no honest way to close it. The
 * dishonest way — typing a payment of 5 nobody received — is exactly what a ledger is built to
 * prevent, and it was the only way left.
 *
 * So the difference is written off *as itself*: a fourth kind of ledger entry that closes the
 * debt without claiming any cash arrived, kept in its own total so `paid_amount` never stops
 * meaning money.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderWriteOffTest extends TestCase
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

    /** The accountant: may take money, give it back, and forgive what is left. */
    private function accountant(): array
    {
        return $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ViewOrderPayments,
            PermissionName::RecordOrderPayments,
            PermissionName::ReverseOrderPayments,
            PermissionName::WriteOffOrderPayments,
            PermissionName::SettleOrders,
        );
    }

    /** Someone who takes money at the counter and nothing more. */
    private function cashier(): array
    {
        return $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ViewOrderPayments,
            PermissionName::RecordOrderPayments,
        );
    }

    /** An order costing exactly `$total`, with nothing paid against it. */
    private function order(string $total = '110.00', ?OrderStatus $status = null): Order
    {
        return Order::factory()->create([
            'items_total' => $total,
            'delivery_price' => '0.00',
            'grand_total' => $total,
            'status' => $status ?? OrderStatus::Delivered,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeOff(array $headers, Order $order, array $payload): TestResponse
    {
        return $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/payments/write-offs", $payload);
    }

    private function pay(array $headers, Order $order, string $amount): TestResponse
    {
        return $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/payments", [
            'amount' => $amount,
            'method' => PaymentMethod::Cash->value,
        ]);
    }

    private function settle(array $headers, Order $order): TestResponse
    {
        return $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Settled->value,
        ]);
    }

    // ── writing one ─────────────────────────────────────────────────────────────────────

    public function test_writing_off_the_difference_closes_the_remainder_without_touching_the_paid_total(): void
    {
        // Arrange — 110 invoiced, 105 in the envelope
        $order = $this->order('110.00');
        $headers = $this->accountant();
        $this->pay($headers, $order, '105.00')->assertCreated();

        // Act
        $response = $this->writeOff($headers, $order, [
            'amount' => '5.00',
            'reason' => 'الزبون سلّم 105 والفرق لا يُطالَب به',
        ]);

        // Assert — the debt is closed, and not one dinar of it was recorded as money
        $response->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'تم شطب الفرق')
            ->assertJsonPath('data.payment.type', OrderPaymentType::WriteOff->value)
            ->assertJsonPath('data.payment.amount', '5.00')
            ->assertJsonPath('data.payment.method', null)
            ->assertJsonPath('data.summary.paid_amount', '105.00')
            ->assertJsonPath('data.summary.written_off_amount', '5.00')
            ->assertJsonPath('data.summary.remaining_amount', '0.00')
            ->assertJsonPath('data.summary.payment_status', PaymentStatus::WrittenOff->value);

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'type' => OrderPaymentType::WriteOff->value,
            'amount' => '5.00',
            'method' => null,
            'reverses_payment_id' => null,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'paid_amount' => '105.00',
            'written_off_amount' => '5.00',
        ]);
    }

    public function test_the_reason_is_kept_on_the_entry_and_the_actor_is_stamped_on_it(): void
    {
        // Arrange — forgiving money owes the next reader an explanation, and a name
        $order = $this->order('110.00');
        $headers = $this->accountant();

        // Act
        $this->writeOff($headers, $order, ['amount' => '110.00', 'reason' => 'دَين متعذّر'])
            ->assertCreated();

        // Assert
        $entry = OrderPayment::query()->where('order_id', $order->id)->sole();

        $this->assertSame(OrderPaymentType::WriteOff, $entry->type);
        $this->assertSame('دَين متعذّر', $entry->notes);
        $this->assertNotNull($entry->recorded_by);
    }

    public function test_an_order_written_off_in_full_reads_as_written_off_rather_than_paid(): void
    {
        // Arrange — nothing was ever collected on this one
        $order = $this->order('110.00');
        $headers = $this->accountant();

        // Act
        $response = $this->writeOff($headers, $order, [
            'amount' => '110.00',
            'reason' => 'شُطبت بالكامل بقرار الإدارة',
        ]);

        // Assert — «مدفوعة بالكامل» would say cash arrived, and none did
        $response->assertCreated()
            ->assertJsonPath('data.summary.paid_amount', '0.00')
            ->assertJsonPath('data.summary.payment_status', PaymentStatus::WrittenOff->value);
    }

    public function test_a_partial_write_off_leaves_the_rest_outstanding(): void
    {
        // Arrange — 5 forgiven on a 110 order that has been paid nothing
        $order = $this->order('110.00');
        $headers = $this->accountant();

        // Act
        $response = $this->writeOff($headers, $order, ['amount' => '5.00', 'reason' => 'خصم تسوية']);

        // Assert — still 105 to chase, so the order is still in the chasing queue
        $response->assertCreated()
            ->assertJsonPath('data.summary.remaining_amount', '105.00')
            ->assertJsonPath('data.summary.payment_status', PaymentStatus::PartiallyPaid->value);
    }

    // ── the point of it ─────────────────────────────────────────────────────────────────

    public function test_an_order_whose_difference_was_written_off_may_be_settled(): void
    {
        // Arrange — the whole reason this feature exists
        $order = $this->order('110.00');
        $headers = $this->accountant();
        $this->pay($headers, $order, '105.00')->assertCreated();
        $this->writeOff($headers, $order, ['amount' => '5.00', 'reason' => 'الفرق لا يُطالَب به'])
            ->assertCreated();

        // Act
        $response = $this->settle($headers, $order);

        // Assert
        $response->assertOk()->assertJsonPath('data.status', OrderStatus::Settled->value);
        $this->assertSame(OrderStatus::Settled, $order->fresh()->status);
    }

    public function test_settlement_is_still_refused_while_the_difference_stands_unwritten(): void
    {
        // Arrange — 105 of 110, and nobody has decided anything about the 5 yet
        $order = $this->order('110.00');
        $headers = $this->accountant();
        $this->pay($headers, $order, '105.00')->assertCreated();

        // Act
        $response = $this->settle($headers, $order);

        // Assert — the guard still names what is missing rather than hiding the move
        $response->assertStatus(422);
        $this->assertStringContainsString('5.00', (string) $response->json('message'));
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_a_settled_order_does_not_report_unrecorded_money_when_the_gap_was_written_off(): void
    {
        // Arrange — the warning line exists for money nobody accounted for; this money was
        // accounted for, by somebody, on the record.
        $order = $this->order('110.00');
        $headers = $this->accountant();
        $this->pay($headers, $order, '105.00')->assertCreated();
        $this->writeOff($headers, $order, ['amount' => '5.00', 'reason' => 'فرق مشطوب'])->assertCreated();
        $this->settle($headers, $order)->assertOk();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}/payments");

        // Assert
        $response->assertOk()->assertJsonPath('data.summary.has_unrecorded_money', false);
        $this->assertFalse($order->fresh()->hasUnrecordedMoney());
    }

    // ── the rules ───────────────────────────────────────────────────────────────────────

    public function test_a_write_off_larger_than_the_remainder_is_refused(): void
    {
        // Arrange — 5 is all that is left; 50 is a slipped keystroke
        $order = $this->order('110.00');
        $headers = $this->accountant();
        $this->pay($headers, $order, '105.00')->assertCreated();

        // Act
        $response = $this->writeOff($headers, $order, ['amount' => '50.00', 'reason' => 'خطأ']);

        // Assert — refused whole: no entry, and the order's money untouched
        $response->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->assertDatabaseMissing('order_payments', [
            'order_id' => $order->id,
            'type' => OrderPaymentType::WriteOff->value,
        ]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'written_off_amount' => '0.00']);
    }

    public function test_nothing_is_written_off_against_an_order_that_owes_nothing(): void
    {
        // Arrange — paid in full, so there is no difference to forgive
        $order = $this->order('110.00');
        $headers = $this->accountant();
        $this->pay($headers, $order, '110.00')->assertCreated();

        // Act
        $response = $this->writeOff($headers, $order, ['amount' => '5.00', 'reason' => 'لا شيء']);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_a_write_off_is_refused_on_a_cancelled_order(): void
    {
        // Arrange — an order written off entirely owes nothing to forgive
        $order = $this->order('110.00', OrderStatus::Cancelled);
        $headers = $this->accountant();

        // Act
        $response = $this->writeOff($headers, $order, ['amount' => '5.00', 'reason' => 'محاولة']);

        // Assert
        $response->assertStatus(422);
        $this->assertDatabaseCount('order_payments', 0);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('badPayloads')]
    public function test_the_payload_is_validated(array $payload, string $field): void
    {
        // Arrange
        $order = $this->order('110.00');
        $headers = $this->accountant();

        // Act
        $response = $this->writeOff($headers, $order, $payload);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors($field);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function badPayloads(): array
    {
        return [
            'no amount' => [['reason' => 'سبب كافٍ'], 'amount'],
            'zero' => [['amount' => '0', 'reason' => 'سبب كافٍ'], 'amount'],
            'negative' => [['amount' => '-5', 'reason' => 'سبب كافٍ'], 'amount'],
            'not a number' => [['amount' => 'كثير', 'reason' => 'سبب كافٍ'], 'amount'],
            'no reason' => [['amount' => '5.00'], 'reason'],
            'reason too short' => [['amount' => '5.00', 'reason' => 'ا'], 'reason'],
            'reason too long' => [['amount' => '5.00', 'reason' => str_repeat('ا', 1001)], 'reason'],
        ];
    }

    // ── who may ─────────────────────────────────────────────────────────────────────────

    public function test_taking_money_does_not_grant_forgiving_it(): void
    {
        // Arrange — the line this permission exists to draw: the receptionist collects, the
        // accountant decides a debt is not worth chasing.
        $order = $this->order('110.00');
        $headers = $this->cashier();

        // Act
        $response = $this->writeOff($headers, $order, ['amount' => '5.00', 'reason' => 'فرق']);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_a_write_off_needs_a_signed_in_user(): void
    {
        // Arrange
        $order = $this->order('110.00');

        // Act
        $response = $this->postJson("/api/v1/orders/{$order->id}/payments/write-offs", [
            'amount' => '5.00',
            'reason' => 'فرق',
        ]);

        // Assert
        $response->assertUnauthorized();
    }

    public function test_a_write_off_against_another_order_is_a_404(): void
    {
        // Arrange
        $headers = $this->accountant();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson('/api/v1/orders/99999/payments/write-offs', ['amount' => '5.00', 'reason' => 'فرق']);

        // Assert
        $response->assertNotFound();
    }

    // ── undoing one ─────────────────────────────────────────────────────────────────────

    public function test_reversing_a_write_off_reopens_the_debt(): void
    {
        // Arrange — forgiven by mistake, on the wrong order
        $order = $this->order('110.00');
        $headers = $this->accountant();
        $this->pay($headers, $order, '105.00')->assertCreated();
        $writeOff = $this->writeOff($headers, $order, ['amount' => '5.00', 'reason' => 'فرق'])
            ->json('data.payment.id');

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/payments/{$writeOff}/reverse", [
                'reason' => 'شُطب على الطلبية الخطأ',
            ]);

        // Assert — the write-off comes back off its own total, not off the cash
        $response->assertCreated()
            ->assertJsonPath('data.summary.paid_amount', '105.00')
            ->assertJsonPath('data.summary.written_off_amount', '0.00')
            ->assertJsonPath('data.summary.remaining_amount', '5.00')
            ->assertJsonPath('data.summary.payment_status', PaymentStatus::PartiallyPaid->value);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'paid_amount' => '105.00',
            'written_off_amount' => '0.00',
        ]);
    }

    public function test_a_reversed_write_off_blocks_settlement_again(): void
    {
        // Arrange
        $order = $this->order('110.00');
        $headers = $this->accountant();
        $this->pay($headers, $order, '105.00')->assertCreated();
        $writeOff = $this->writeOff($headers, $order, ['amount' => '5.00', 'reason' => 'فرق'])
            ->json('data.payment.id');
        $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/payments/{$writeOff}/reverse", ['reason' => 'شُطب خطأً'])
            ->assertCreated();

        // Act
        $response = $this->settle($headers, $order);

        // Assert
        $response->assertStatus(422);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_a_write_off_is_not_written_off_twice(): void
    {
        // Arrange
        $order = $this->order('110.00');
        $headers = $this->accountant();
        $writeOff = $this->writeOff($headers, $order, ['amount' => '5.00', 'reason' => 'فرق'])
            ->json('data.payment.id');
        $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/payments/{$writeOff}/reverse", ['reason' => 'خطأ'])
            ->assertCreated();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/payments/{$writeOff}/reverse", ['reason' => 'مرة ثانية']);

        // Assert
        $response->assertStatus(422);
        $this->assertSame(1, OrderPayment::query()->where('reverses_payment_id', $writeOff)->count());
    }

    // ── the invariant ───────────────────────────────────────────────────────────────────

    public function test_the_ledger_and_the_two_totals_never_disagree(): void
    {
        // Arrange — a full life: two payments, a refund, a write-off, and a write-off undone
        $order = $this->order('450.00');
        $headers = $this->accountant();

        // Act
        $this->pay($headers, $order, '150.00')->assertCreated();
        $this->pay($headers, $order, '200.00')->assertCreated();
        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/payments/refunds", [
            'amount' => '50.00',
            'method' => PaymentMethod::Cash->value,
        ])->assertCreated();
        $undone = $this->writeOff($headers, $order, ['amount' => '20.00', 'reason' => 'شطب أول'])
            ->json('data.payment.id');
        $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/payments/{$undone}/reverse", ['reason' => 'تراجع'])
            ->assertCreated();
        $this->writeOff($headers, $order, ['amount' => '30.00', 'reason' => 'شطب ثانٍ'])->assertCreated();

        // Assert — both cached totals are exactly what the rows add up to
        $fresh = $order->fresh();
        $paid = '0';
        $written = '0';

        foreach (OrderPayment::query()->where('order_id', $order->id)->with('reversedPayment')->get() as $entry) {
            $bucket = $entry->affectsWriteOff() ? 'written' : 'paid';
            ${$bucket} = bcadd(${$bucket}, $entry->signedAmount(), 2);
        }

        $this->assertSame('300.00', $paid);
        $this->assertSame('30.00', $written);
        $this->assertSame($paid, (string) $fresh->paid_amount);
        $this->assertSame($written, (string) $fresh->written_off_amount);
        $this->assertSame('120.00', $fresh->remainingAmount());
    }

    public function test_a_write_off_is_not_counted_as_cash_collected(): void
    {
        // Arrange — the failure this design exists to prevent: 5 dinars that never arrived
        // showing up in the drawer report as if they had.
        $order = $this->order('110.00');
        $headers = $this->accountant();
        $this->pay($headers, $order, '105.00')->assertCreated();
        $this->writeOff($headers, $order, ['amount' => '5.00', 'reason' => 'فرق'])->assertCreated();
        $this->settle($headers, $order)->assertOk();

        $reader = $this->auth(PermissionName::ViewProfitAndLossReport);
        $from = now()->subDay()->toDateString();
        $to = now()->addDay()->toDateString();

        // The container is reused inside one test, so the guard would go on answering with the
        // accountant who did the work above — and they may not read the report. See RULES.md §6.
        $this->app->get('auth')->forgetGuards();

        // Act
        $response = $this->withHeaders($reader)
            ->getJson("/api/v1/reports/profit-loss?from={$from}&to={$to}");

        // Assert — collected is cash, written off is a loss, and the two are never one number
        $response->assertOk()
            ->assertJsonPath('data.cash_collected', '105.00')
            ->assertJsonPath('data.write_offs', '5.00');
    }
}
