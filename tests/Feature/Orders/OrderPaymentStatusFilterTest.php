<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentStatus;
use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Filtering the orders list by where each order stands on its money.
 *
 * **This file exists because that filter is the one place the payment-status rule is written
 * twice.** `PaymentStatus::between()` decides it in PHP for a single order; the list has to
 * decide it in SQL for a page of them, and there is no column to read it from — see
 * `PaymentStatus` for why storing one would rot. Two copies of a rule drift, so every test below
 * asserts the SQL against **the enum itself** rather than against a hand-written expectation.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderPaymentStatusFilterTest extends TestCase
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
    private function auth(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::ViewOrders->value);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * The two money columns are written directly here rather than through the endpoints, because
     * these are tests about the *filter*, not about the ledger — and one of the five states
     * cannot be reached through the write path at all: `RecordOrderPayment` refuses a payment
     * larger than what is outstanding, so an overpaid order only ever arises from a total that
     * dropped afterwards.
     */
    private function order(
        string $grandTotal,
        string $paid,
        string $writtenOff = '0.00',
        string $carrierSettled = '0.00',
    ): Order {
        return Order::factory()->create([
            'items_total' => $grandTotal,
            'delivery_price' => '0.00',
            'grand_total' => $grandTotal,
            'paid_amount' => $paid,
            'written_off_amount' => $writtenOff,
            'carrier_settled_amount' => $carrierSettled,
        ]);
    }

    /**
     * One order in each of the five states, keyed by the state the enum says they are in.
     *
     * The last of them is the pair this file is really about: 400 collected and 50 forgiven adds
     * up to the same 450 as the order above it, and the two must not come out of SQL wearing the
     * same badge.
     *
     * @return array<string, Order>
     */
    private function oneOfEach(): array
    {
        $orders = [
            PaymentStatus::Unpaid->value => $this->order('450.00', '0.00'),
            PaymentStatus::PartiallyPaid->value => $this->order('450.00', '150.00'),
            PaymentStatus::Paid->value => $this->order('450.00', '450.00'),
            PaymentStatus::Overpaid->value => $this->order('400.00', '450.00'),
            PaymentStatus::WrittenOff->value => $this->order('450.00', '400.00', '50.00'),
        ];

        // The guard that makes this whole file meaningful: if the enum ever disagrees with the
        // arrangement above, every assertion below would be checking SQL against a fiction.
        foreach ($orders as $expected => $order) {
            $this->assertSame(
                $expected,
                $order->paymentStatus()->value,
                'the fixture no longer matches PaymentStatus::between()',
            );
        }

        return $orders;
    }

    #[DataProvider('states')]
    public function test_the_list_can_be_narrowed_to_one_payment_state(string $state): void
    {
        // Arrange
        $orders = $this->oneOfEach();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders?payment_status={$state}");

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $orders[$state]->id)
            ->assertJsonPath('data.0.payment_status', $state);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function states(): array
    {
        return array_combine(
            PaymentStatus::values(),
            array_map(fn (string $value) => [$value], PaymentStatus::values()),
        );
    }

    public function test_an_order_that_costs_nothing_reads_as_paid_rather_than_unpaid(): void
    {
        // Arrange — the edge the ordering in PaymentStatus::between() exists for. «غير مدفوعة»
        // would put it in a queue somebody is meant to chase, and nothing is owed on it.
        $free = $this->order('0.00', '0.00');
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/orders?payment_status='.PaymentStatus::Unpaid->value);

        // Assert
        $this->assertSame(PaymentStatus::Paid, $free->paymentStatus());
        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_order_closed_at_the_carrier_reads_paid_in_sql_too(): void
    {
        // Arrange — the third total joined the rule when the Nawris integration landed, and it
        // had to join it in *both* languages. An order of 450 whose customer paid 430 to us and
        // 20 to the courier owes nothing, and the list must say so.
        //
        // Note it reads «مدفوعة بالكامل» and not «مشطوب فرقها»: nothing was forgiven here, which
        // is exactly the branch a carrier settlement deliberately does not take.
        $order = $this->order('450.00', '430.00', '0.00', '20.00');
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/orders?payment_status='.PaymentStatus::Paid->value);

        // Assert — the enum first, then the SQL against it.
        $this->assertSame(PaymentStatus::Paid, $order->paymentStatus());
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.payment_status', PaymentStatus::Paid->value);
    }

    public function test_a_part_paid_order_stays_part_paid_when_the_carrier_covers_only_some(): void
    {
        // Arrange — the guard against the new column closing an order it has not closed: 300 of
        // 450 collected and 20 taken at the door still leaves 130 owed.
        $order = $this->order('450.00', '300.00', '0.00', '20.00');
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/orders?payment_status='.PaymentStatus::PartiallyPaid->value);

        // Assert
        $this->assertSame(PaymentStatus::PartiallyPaid, $order->paymentStatus());
        $this->assertSame('130.00', $order->remainingAmount());
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $order->id);
    }

    public function test_the_filter_is_repeatable(): void
    {
        // Arrange — «أرِني ما لم يُدفع» means unpaid *and* part-paid in practice.
        $orders = $this->oneOfEach();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson(
            '/api/v1/orders?payment_status[]='.PaymentStatus::Unpaid->value.
            '&payment_status[]='.PaymentStatus::PartiallyPaid->value,
        );

        // Assert
        $response->assertOk()->assertJsonCount(2, 'data');

        $returned = array_column($response->json('data'), 'id');
        sort($returned);
        $expected = [
            $orders[PaymentStatus::Unpaid->value]->id,
            $orders[PaymentStatus::PartiallyPaid->value]->id,
        ];
        sort($expected);

        $this->assertSame($expected, $returned);
    }

    public function test_an_unknown_payment_state_is_ignored_rather_than_emptying_the_list(): void
    {
        // Arrange — the same behaviour the status filter already has: a filter nobody can
        // satisfy would look like «لا توجد طلبيات» instead of «سألت عن شيء غير موجود».
        $this->oneOfEach();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders?payment_status=nonsense');

        // Assert
        $response->assertOk()->assertJsonCount(count(PaymentStatus::cases()), 'data');
    }

    // ── the counts beside the filter ────────────────────────────────────────────────────

    public function test_the_summary_counts_every_payment_state_including_the_zeros(): void
    {
        // Arrange
        $this->oneOfEach();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/summary');

        // Assert — one of each, and every state present. A missing key would leave a client
        // choosing between a blank and a zero, and those mean different things.
        $response->assertOk();

        foreach (PaymentStatus::values() as $state) {
            $response->assertJsonPath("data.payment_counts.{$state}", 1);
        }
    }

    public function test_the_counts_and_the_list_cannot_disagree(): void
    {
        // Arrange — the point of sharing one SQL expression between the filter and the counts:
        // «غير مدفوعة ٧» must never sit above a list of four.
        $this->oneOfEach();
        $this->order('450.00', '0.00');
        $headers = $this->auth();

        // Act
        $summary = $this->withHeaders($headers)->getJson('/api/v1/orders/summary');
        $list = $this->withHeaders($headers)
            ->getJson('/api/v1/orders?payment_status='.PaymentStatus::Unpaid->value);

        // Assert
        $counted = $summary->json('data.payment_counts.'.PaymentStatus::Unpaid->value);

        $this->assertSame(2, $counted);
        $this->assertCount($counted, $list->json('data'));
    }

    public function test_the_payment_counts_are_not_narrowed_by_the_payment_filter_itself(): void
    {
        // Arrange — counts narrowed to the state already chosen would every one of them equal
        // the list's own length, which is the same trap the status counts avoid.
        $this->oneOfEach();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/orders/summary?payment_status='.PaymentStatus::Paid->value);

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.payment_counts.'.PaymentStatus::Paid->value, 1)
            ->assertJsonPath('data.payment_counts.'.PaymentStatus::Unpaid->value, 1);
    }

    public function test_the_payment_counts_do_narrow_the_status_counts(): void
    {
        // Arrange — the other axis *is* applied, because «كم طلبية غير مدفوعة في كل حالة؟» is a
        // real question and the two axes cross.
        $unpaid = $this->order('450.00', '0.00');
        $unpaid->forceFill(['status' => OrderStatus::Ready])->save();
        $paid = $this->order('450.00', '450.00');
        $paid->forceFill(['status' => OrderStatus::Ready])->save();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/orders/summary?payment_status='.PaymentStatus::Unpaid->value);

        // Assert
        $response->assertOk()->assertJsonPath('data.counts.'.OrderStatus::Ready->value, 1);
    }

    public function test_the_home_screen_carries_the_payment_states_with_their_arabic(): void
    {
        // Arrange
        $this->oneOfEach();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/home/summary');

        // Assert — a list with the label beside the number, so the app holds no translation
        // table and a state added later needs no app release.
        $response->assertOk()->assertJsonCount(count(PaymentStatus::cases()), 'data.payments');

        foreach (PaymentStatus::cases() as $index => $status) {
            $response->assertJsonPath("data.payments.{$index}.status", $status->value)
                ->assertJsonPath("data.payments.{$index}.label", $status->label())
                ->assertJsonPath("data.payments.{$index}.count", 1);
        }
    }

    public function test_the_payment_filter_composes_with_the_others(): void
    {
        // Arrange
        $orders = $this->oneOfEach();
        $wanted = $orders[PaymentStatus::PartiallyPaid->value];
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson(
            '/api/v1/orders?payment_status='.PaymentStatus::PartiallyPaid->value.
            '&customer_id='.$wanted->customer_id,
        );

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wanted->id);
    }
}
