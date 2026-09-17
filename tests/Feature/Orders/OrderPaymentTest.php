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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The money ledger on an order.
 *
 * The behaviour under test is not «a number went up». It is that **the ledger is the truth and
 * `paid_amount` is only its sum** — so most of these assert both, and one walks every path to
 * prove the two can never be observed apart.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderPaymentTest extends TestCase
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

    /** Someone allowed to do everything with an order's money. */
    private function cashier(): array
    {
        return $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ViewOrderPayments,
            PermissionName::RecordOrderPayments,
            PermissionName::ReverseOrderPayments,
        );
    }

    /** An order costing exactly `$total`, with nothing paid against it. */
    private function order(string $total = '450.00', ?OrderStatus $status = null): Order
    {
        return Order::factory()->create([
            'items_total' => $total,
            'delivery_price' => '0.00',
            'grand_total' => $total,
            'status' => $status ?? OrderStatus::Ready,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pay(array $headers, Order $order, array $payload): TestResponse
    {
        return $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/payments", $payload);
    }

    // ── recording ───────────────────────────────────────────────────────────────────────

    public function test_recording_a_payment_writes_a_ledger_entry_and_moves_the_total(): void
    {
        // Arrange
        $order = $this->order('450.00');
        $headers = $this->cashier();

        // Act
        $response = $this->pay($headers, $order, [
            'amount' => '150.00',
            'method' => PaymentMethod::Cash->value,
            'notes' => 'عربون',
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'تم تسجيل الدفعة بنجاح')
            ->assertJsonPath('data.payment.type', OrderPaymentType::Payment->value)
            ->assertJsonPath('data.payment.amount', '150.00')
            ->assertJsonPath('data.payment.method', PaymentMethod::Cash->value)
            ->assertJsonPath('data.summary.paid_amount', '150.00')
            ->assertJsonPath('data.summary.remaining_amount', '300.00')
            ->assertJsonPath('data.summary.payment_status', PaymentStatus::PartiallyPaid->value);

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'type' => OrderPaymentType::Payment->value,
            'amount' => '150.00',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'paid_amount' => '150.00']);
    }

    public function test_the_entry_is_attributed_to_the_signed_in_user(): void
    {
        // Arrange
        $order = $this->order();
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::RecordOrderPayments->value);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        // Act
        $response = $this->pay($headers, $order, [
            'amount' => '50.00',
            'method' => PaymentMethod::Cash->value,
            // A colleague's id in the payload must change nothing: `recorded_by` is stamped.
            'recorded_by' => User::factory()->create()->id,
        ]);

        // Assert
        $response->assertCreated();
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'recorded_by' => $user->id,
        ]);
    }

    public function test_paying_the_balance_marks_the_order_fully_paid(): void
    {
        // Arrange
        $order = $this->order('450.00');
        $headers = $this->cashier();
        $this->pay($headers, $order, ['amount' => '150.00', 'method' => PaymentMethod::Cash->value]);

        // Act
        $response = $this->pay($headers, $order, ['amount' => '300.00', 'method' => PaymentMethod::Cash->value]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.summary.paid_amount', '450.00')
            ->assertJsonPath('data.summary.remaining_amount', '0.00')
            ->assertJsonPath('data.summary.payment_status', PaymentStatus::Paid->value)
            ->assertJsonPath('data.summary.payment_status_label', 'مدفوعة بالكامل');
    }

    public function test_a_payment_larger_than_the_remaining_is_refused(): void
    {
        // Arrange — 500 typed where 50 was meant, on an order that owes 450.
        $order = $this->order('450.00');
        $headers = $this->cashier();

        // Act
        $response = $this->pay($headers, $order, ['amount' => '500.00', 'method' => PaymentMethod::Cash->value]);

        // Assert
        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonStructure(['errors' => ['amount']]);

        $this->assertDatabaseCount('order_payments', 0);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'paid_amount' => '0.00']);
    }

    public function test_a_payment_on_a_cancelled_order_is_refused(): void
    {
        // Arrange
        $order = $this->order('450.00', OrderStatus::Cancelled);
        $headers = $this->cashier();

        // Act
        $response = $this->pay($headers, $order, ['amount' => '50.00', 'method' => PaymentMethod::Cash->value]);

        // Assert
        $response->assertStatus(422);
        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_a_refund_on_a_cancelled_order_is_allowed(): void
    {
        // Arrange — the deposit was taken while the order was live, then it was written off.
        $order = $this->order('450.00');
        $headers = $this->cashier();
        $this->pay($headers, $order, ['amount' => '150.00', 'method' => PaymentMethod::Cash->value]);
        $order->forceFill(['status' => OrderStatus::Cancelled])->save();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/payments/refunds", [
                'amount' => '150.00',
                'method' => PaymentMethod::Cash->value,
                'notes' => 'إرجاع العربون بعد الإلغاء',
            ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.payment.type', OrderPaymentType::Refund->value)
            ->assertJsonPath('data.summary.paid_amount', '0.00')
            ->assertJsonPath('data.summary.payment_status', PaymentStatus::Unpaid->value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayments')]
    public function test_a_payment_is_validated(array $payload, string $field): void
    {
        // Arrange
        $order = $this->order();
        $headers = $this->cashier();

        // Act
        $response = $this->pay($headers, $order, $payload);

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => [$field]]);
        $this->assertDatabaseCount('order_payments', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayments(): array
    {
        return [
            'no amount' => [['method' => 'cash'], 'amount'],
            'zero amount' => [['amount' => '0', 'method' => 'cash'], 'amount'],
            'negative amount' => [['amount' => '-10', 'method' => 'cash'], 'amount'],
            'amount is not a number' => [['amount' => 'كثير', 'method' => 'cash'], 'amount'],
            'no method' => [['amount' => '10'], 'method'],
            'unknown method' => [['amount' => '10', 'method' => 'bitcoin'], 'method'],
            'future paid_at' => [
                ['amount' => '10', 'method' => 'cash', 'paid_at' => '2099-01-01'],
                'paid_at',
            ],
        ];
    }

    public function test_a_payment_may_be_back_dated_but_not_forward_dated(): void
    {
        // Arrange — the deposit was taken on Thursday and entered on Saturday.
        $order = $this->order();
        $headers = $this->cashier();
        $thursday = now()->subDays(2)->startOfHour();

        // Act
        $response = $this->pay($headers, $order, [
            'amount' => '50.00',
            'method' => PaymentMethod::Cash->value,
            'paid_at' => $thursday->toIso8601String(),
        ]);

        // Assert
        $response->assertCreated();
        $this->assertTrue(
            OrderPayment::query()->firstOrFail()->paid_at->equalTo($thursday),
            'paid_at must record when the money moved, not when it was typed in',
        );
    }

    // ── the receipt ─────────────────────────────────────────────────────────────────────

    public function test_a_bank_transfer_without_a_receipt_is_refused(): void
    {
        // Arrange
        $order = $this->order();
        $headers = $this->cashier();

        // Act
        $response = $this->pay($headers, $order, [
            'amount' => '150.00',
            'method' => PaymentMethod::BankTransfer->value,
            'reference' => 'TRF-9910',
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['receipt']]);
        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_a_bank_transfer_with_a_pdf_receipt_is_stored(): void
    {
        // Arrange
        Storage::fake('local');
        $order = $this->order();
        $headers = $this->cashier();
        $receipt = UploadedFile::fake()->create('waseel.pdf', 120, 'application/pdf');

        // Act
        $response = $this->withHeaders($headers)->post(
            "/api/v1/orders/{$order->id}/payments",
            [
                'amount' => '150.00',
                'method' => PaymentMethod::BankTransfer->value,
                'reference' => 'TRF-9910',
                'receipt' => $receipt,
            ],
            ['Accept' => 'application/json'],
        );

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.payment.has_receipt', true)
            ->assertJsonPath('data.payment.receipt_is_image', false)
            ->assertJsonPath('data.payment.receipt_filename', 'waseel.pdf');

        $payment = OrderPayment::query()->firstOrFail();
        $this->assertNotNull($payment->receipt_path);
        Storage::disk('local')->assertExists($payment->receipt_path);
        $this->assertStringEndsWith('.pdf', $payment->receipt_path);
        // The stored name is generated, never the client's — two customers sending
        // "receipt.pdf" must not collide, and nobody chooses a path.
        $this->assertStringNotContainsString('waseel', $payment->receipt_path);
    }

    public function test_a_bank_transfer_with_an_image_receipt_is_stored(): void
    {
        // Arrange — a photographed receipt, which is how most of them actually arrive.
        Storage::fake('local');
        $order = $this->order();
        $headers = $this->cashier();

        // Act
        $response = $this->withHeaders($headers)->post(
            "/api/v1/orders/{$order->id}/payments",
            [
                'amount' => '150.00',
                'method' => PaymentMethod::BankTransfer->value,
                'reference' => 'TRF-9910',
                'receipt' => UploadedFile::fake()->image('waseel.jpg'),
            ],
            ['Accept' => 'application/json'],
        );

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.payment.has_receipt', true)
            ->assertJsonPath('data.payment.receipt_is_image', true)
            ->assertJsonPath('data.payment.receipt_filename', 'waseel.jpg');

        $payment = OrderPayment::query()->firstOrFail();
        Storage::disk('local')->assertExists($payment->receipt_path);
        $this->assertStringEndsWith('.jpg', $payment->receipt_path);
    }

    public function test_a_stored_receipt_takes_its_extension_from_the_bytes_not_the_name(): void
    {
        // Arrange — real JPEG bytes wearing a PDF's name and a PDF's claimed Content-Type.
        // Not `UploadedFile::fake()`, deliberately: the fake answers mime questions from the
        // *name*, which is exactly the lie this test needs to see through, so a genuine
        // temporary file is built for finfo to sniff.
        Storage::fake('local');
        $order = $this->order();
        $headers = $this->cashier();

        $path = (string) tempnam(sys_get_temp_dir(), 'receipt');
        imagejpeg(imagecreatetruecolor(8, 8), $path);
        $receipt = new UploadedFile($path, 'waseel.pdf', 'application/pdf', null, true);

        // Act
        $response = $this->withHeaders($headers)->post(
            "/api/v1/orders/{$order->id}/payments",
            [
                'amount' => '150.00',
                'method' => PaymentMethod::BankTransfer->value,
                'receipt' => $receipt,
            ],
            ['Accept' => 'application/json'],
        );

        // Assert — sniffed as an image, stored as one, told apart as one.
        $response->assertCreated()->assertJsonPath('data.payment.receipt_is_image', true);
        $this->assertStringEndsWith('.jpg', OrderPayment::query()->firstOrFail()->receipt_path);
    }

    #[DataProvider('nonDocumentReceipts')]
    public function test_a_receipt_that_is_neither_pdf_nor_image_is_refused(string $name, string $mime): void
    {
        // Arrange
        Storage::fake('local');
        $order = $this->order();
        $headers = $this->cashier();

        // Act
        $response = $this->withHeaders($headers)->post(
            "/api/v1/orders/{$order->id}/payments",
            [
                'amount' => '150.00',
                'method' => PaymentMethod::BankTransfer->value,
                'receipt' => UploadedFile::fake()->create($name, 10, $mime),
            ],
            ['Accept' => 'application/json'],
        );

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['receipt']]);
        $this->assertDatabaseCount('order_payments', 0);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nonDocumentReceipts(): array
    {
        return [
            'a Word document' => [
                'waseel.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
            // An SVG is an HTML document wearing an image's clothes — served from our own
            // origin it is stored XSS, so it stays out even now that images are in.
            'an SVG' => ['waseel.svg', 'image/svg+xml'],
        ];
    }

    public function test_a_refund_by_transfer_stores_its_image_receipt(): void
    {
        // Arrange — money in by cash, some of it going back by transfer with the proof
        // photographed. The refund request holds its own copy of the receipt rules, so the
        // refund path is proven separately from the payment path.
        Storage::fake('local');
        $order = $this->order();
        $headers = $this->cashier();
        $this->pay($headers, $order, ['amount' => '200.00', 'method' => PaymentMethod::Cash->value]);

        // Act
        $response = $this->withHeaders($headers)->post(
            "/api/v1/orders/{$order->id}/payments/refunds",
            [
                'amount' => '80.00',
                'method' => PaymentMethod::BankTransfer->value,
                'receipt' => UploadedFile::fake()->image('back.png'),
            ],
            ['Accept' => 'application/json'],
        );

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.payment.type', OrderPaymentType::Refund->value)
            ->assertJsonPath('data.payment.has_receipt', true)
            ->assertJsonPath('data.payment.receipt_is_image', true);

        $payment = OrderPayment::query()
            ->where('type', OrderPaymentType::Refund->value)
            ->firstOrFail();
        Storage::disk('local')->assertExists($payment->receipt_path);
        $this->assertStringEndsWith('.png', $payment->receipt_path);
    }

    public function test_cash_needs_no_receipt(): void
    {
        // Arrange
        $order = $this->order();
        $headers = $this->cashier();

        // Act
        $response = $this->pay($headers, $order, ['amount' => '150.00', 'method' => PaymentMethod::Cash->value]);

        // Assert
        $response->assertCreated()->assertJsonPath('data.payment.has_receipt', false);
    }

    // ── refunds ─────────────────────────────────────────────────────────────────────────

    public function test_a_refund_larger_than_what_was_paid_is_refused(): void
    {
        // Arrange
        $order = $this->order('450.00');
        $headers = $this->cashier();
        $this->pay($headers, $order, ['amount' => '100.00', 'method' => PaymentMethod::Cash->value]);

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/payments/refunds", [
                'amount' => '150.00',
                'method' => PaymentMethod::Cash->value,
            ]);

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['amount']]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'paid_amount' => '100.00']);
    }

    // ── reversal ────────────────────────────────────────────────────────────────────────

    public function test_reversing_a_payment_leaves_both_rows_and_undoes_the_total(): void
    {
        // Arrange — 500 typed where 50 was meant, on an order that can absorb it.
        $order = $this->order('900.00');
        $headers = $this->cashier();
        $this->pay($headers, $order, ['amount' => '500.00', 'method' => PaymentMethod::Cash->value]);
        $payment = OrderPayment::query()->firstOrFail();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/payments/{$payment->id}/reverse", [
                'reason' => 'خطأ في المبلغ، الصحيح ٥٠',
            ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.payment.type', OrderPaymentType::Reversal->value)
            ->assertJsonPath('data.payment.amount', '500.00')
            ->assertJsonPath('data.payment.reverses_payment_id', $payment->id)
            ->assertJsonPath('data.summary.paid_amount', '0.00');

        // The wrong entry is still there — that is the whole point of a ledger.
        $this->assertDatabaseHas('order_payments', ['id' => $payment->id, 'amount' => '500.00']);
        $this->assertDatabaseCount('order_payments', 2);
        $this->assertTrue($payment->refresh()->isReversed());
    }

    public function test_a_reversal_demands_a_reason(): void
    {
        // Arrange
        $order = $this->order();
        $headers = $this->cashier();
        $this->pay($headers, $order, ['amount' => '50.00', 'method' => PaymentMethod::Cash->value]);
        $payment = OrderPayment::query()->firstOrFail();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/payments/{$payment->id}/reverse", []);

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);
        $this->assertDatabaseCount('order_payments', 1);
    }

    public function test_an_entry_cannot_be_reversed_twice(): void
    {
        // Arrange
        $order = $this->order();
        $headers = $this->cashier();
        $this->pay($headers, $order, ['amount' => '50.00', 'method' => PaymentMethod::Cash->value]);
        $payment = OrderPayment::query()->firstOrFail();
        $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/payments/{$payment->id}/reverse", ['reason' => 'خطأ']);

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/payments/{$payment->id}/reverse", ['reason' => 'مرة أخرى']);

        // Assert
        $response->assertStatus(422);
        $this->assertDatabaseCount('order_payments', 2);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'paid_amount' => '0.00']);
    }

    public function test_a_reversal_cannot_itself_be_reversed(): void
    {
        // Arrange
        $order = $this->order();
        $headers = $this->cashier();
        $this->pay($headers, $order, ['amount' => '50.00', 'method' => PaymentMethod::Cash->value]);
        $payment = OrderPayment::query()->firstOrFail();
        $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/payments/{$payment->id}/reverse", ['reason' => 'خطأ']);
        $reversal = OrderPayment::query()->where('type', OrderPaymentType::Reversal->value)->firstOrFail();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/payments/{$reversal->id}/reverse", ['reason' => 'تراجع']);

        // Assert
        $response->assertStatus(422);
        $this->assertDatabaseCount('order_payments', 2);
    }

    public function test_a_refund_cannot_be_reversed(): void
    {
        // Arrange — undoing a refund is a *payment*: the customer really handed it back.
        $order = $this->order();
        $headers = $this->cashier();
        $this->pay($headers, $order, ['amount' => '50.00', 'method' => PaymentMethod::Cash->value]);
        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/payments/refunds", [
            'amount' => '50.00',
            'method' => PaymentMethod::Cash->value,
        ]);
        $refund = OrderPayment::query()->where('type', OrderPaymentType::Refund->value)->firstOrFail();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/payments/{$refund->id}/reverse", ['reason' => 'تراجع']);

        // Assert
        $response->assertStatus(422);
        $this->assertDatabaseCount('order_payments', 2);
    }

    // ── reading ─────────────────────────────────────────────────────────────────────────

    public function test_the_ledger_lists_oldest_first_with_the_summary(): void
    {
        // Arrange
        $order = $this->order('450.00');
        $headers = $this->cashier();
        $this->pay($headers, $order, ['amount' => '100.00', 'method' => PaymentMethod::Cash->value]);
        $this->pay($headers, $order, ['amount' => '200.00', 'method' => PaymentMethod::BankCard->value]);

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}/payments");

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(2, 'data.payments')
            ->assertJsonPath('data.payments.0.amount', '100.00')
            ->assertJsonPath('data.payments.1.amount', '200.00')
            ->assertJsonPath('data.summary.paid_amount', '300.00')
            ->assertJsonPath('data.summary.remaining_amount', '150.00');
    }

    public function test_the_order_payload_carries_the_three_numbers(): void
    {
        // Arrange
        $order = $this->order('450.00');
        $headers = $this->cashier();
        $this->pay($headers, $order, ['amount' => '150.00', 'method' => PaymentMethod::Cash->value]);

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.grand_total', '450.00')
            ->assertJsonPath('data.paid_amount', '150.00')
            ->assertJsonPath('data.remaining_amount', '300.00')
            ->assertJsonPath('data.payment_status', PaymentStatus::PartiallyPaid->value)
            ->assertJsonPath('data.has_unrecorded_money', false)
            // The ledger is *not* here: it has its own endpoint behind its own permission, so
            // the printer holding `orders.view` never receives it.
            ->assertJsonMissingPath('data.payments');
    }

    public function test_the_order_payload_never_carries_the_ledger(): void
    {
        // Arrange — the printer: allowed to see the order, not what the customer paid.
        $order = $this->order('450.00');
        $cashier = $this->cashier();
        $this->pay($cashier, $order, ['amount' => '150.00', 'method' => PaymentMethod::Cash->value]);
        $printer = $this->auth(PermissionName::ViewOrders);

        // Act
        $response = $this->withHeaders($printer)->getJson("/api/v1/orders/{$order->id}");

        // Assert — the summary is a property of the order and stays; the entries do not.
        $response->assertOk()
            ->assertJsonPath('data.paid_amount', '150.00')
            ->assertJsonMissingPath('data.payments');
    }

    public function test_a_settled_order_with_money_outstanding_is_flagged(): void
    {
        // Arrange — settling writes no ledger entry, deliberately. The gap is surfaced instead
        // of papered over with an entry nobody made.
        $order = $this->order('450.00', OrderStatus::Settled);
        $headers = $this->cashier();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.remaining_amount', '450.00')
            ->assertJsonPath('data.has_unrecorded_money', true);
    }

    public function test_a_cancelled_order_is_never_flagged_for_unrecorded_money(): void
    {
        // Arrange — nothing is owed on an order that was written off.
        $order = $this->order('450.00', OrderStatus::Cancelled);
        $headers = $this->cashier();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert
        $response->assertOk()->assertJsonPath('data.has_unrecorded_money', false);
    }

    // ── the invariant ───────────────────────────────────────────────────────────────────

    public function test_the_stored_total_always_equals_the_ledger(): void
    {
        // Arrange
        $order = $this->order('900.00');
        $headers = $this->cashier();

        // Act — every write path, in one order's life.
        $this->pay($headers, $order, ['amount' => '300.00', 'method' => PaymentMethod::Cash->value]);
        $this->pay($headers, $order, ['amount' => '500.00', 'method' => PaymentMethod::Libyana->value]);
        $mistake = OrderPayment::query()->where('amount', '500.00')->firstOrFail();
        $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/payments/{$mistake->id}/reverse", ['reason' => 'خطأ']);
        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/payments/refunds", [
            'amount' => '100.00',
            'method' => PaymentMethod::Cash->value,
        ]);

        // Assert
        $ledger = '0';
        foreach (OrderPayment::query()->where('order_id', $order->id)->get() as $entry) {
            $ledger = bcadd($ledger, $entry->signedAmount(), 2);
        }

        $this->assertSame('200.00', $ledger, '300 in, 500 in then reversed, 100 back');
        $this->assertSame($ledger, (string) $order->refresh()->paid_amount);
    }

    // ── access ──────────────────────────────────────────────────────────────────────────

    public function test_the_ledger_needs_authentication(): void
    {
        // Arrange
        $order = $this->order();

        // Act
        $response = $this->getJson("/api/v1/orders/{$order->id}/payments");

        // Assert
        $response->assertStatus(401);
    }

    public function test_viewing_the_ledger_needs_its_own_permission(): void
    {
        // Arrange — being allowed to see the order is not being allowed to see its money.
        $order = $this->order();
        $headers = $this->auth(PermissionName::ViewOrders);

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}/payments");

        // Assert
        $response->assertStatus(403);
    }

    public function test_recording_needs_its_own_permission(): void
    {
        // Arrange
        $order = $this->order();
        $headers = $this->auth(PermissionName::ViewOrders, PermissionName::ViewOrderPayments);

        // Act
        $response = $this->pay($headers, $order, ['amount' => '50.00', 'method' => PaymentMethod::Cash->value]);

        // Assert
        $response->assertStatus(403);
        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_money_going_out_needs_a_permission_that_taking_it_does_not_grant(): void
    {
        // Arrange — the line the whole feature turns on: a receptionist may take a deposit and
        // may not put a hand back into the drawer.
        $order = $this->order();
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ViewOrderPayments,
            PermissionName::RecordOrderPayments,
        );
        $this->pay($headers, $order, ['amount' => '50.00', 'method' => PaymentMethod::Cash->value]);
        $payment = OrderPayment::query()->firstOrFail();

        // Act
        $refund = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/payments/refunds", [
            'amount' => '50.00',
            'method' => PaymentMethod::Cash->value,
        ]);
        $reversal = $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/payments/{$payment->id}/reverse", ['reason' => 'خطأ']);

        // Assert
        $refund->assertStatus(403);
        $reversal->assertStatus(403);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'paid_amount' => '50.00']);
    }

    public function test_another_orders_entry_is_not_reachable(): void
    {
        // Arrange
        $mine = $this->order();
        $theirs = $this->order();
        $headers = $this->cashier();
        $this->pay($headers, $theirs, ['amount' => '50.00', 'method' => PaymentMethod::Cash->value]);
        $theirPayment = OrderPayment::query()->firstOrFail();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$mine->id}/payments/{$theirPayment->id}/reverse", ['reason' => 'خطأ']);

        // Assert
        $response->assertStatus(404);
        $this->assertFalse($theirPayment->refresh()->isReversed());
    }
}
