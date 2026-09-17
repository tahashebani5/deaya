<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderPaymentType;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Money taken in the moment the parcel changes hands.
 *
 * **The counter is where the cash actually appears**, and until this existed the person holding
 * it had to move the order, leave the screen, open «الدفعات» and type the figure again — two
 * places for one event, and the second one skipped often enough that orders were closing with
 * nothing recorded against them.
 *
 * So «تم الاستلام» and «تم التسوية» each carry a box for what was just taken, and what is typed
 * in it becomes a **real ledger entry** — the same row `RecordOrderPayment` writes from the
 * payments screen, with the same guards, attributed to the same person. Nothing here invents an
 * entry: an empty box records nothing, which is the answer for an order already paid.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderTransitionPaymentTest extends TestCase
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
     * Somebody who hands parcels over and takes the money for them.
     *
     * @return array<string, string>
     */
    private function cashier(): array
    {
        return $this->tokenFor([
            PermissionName::ViewOrders,
            PermissionName::MarkOrdersDelivered,
            PermissionName::SettleOrders,
            PermissionName::RecordOrderPayments,
        ]);
    }

    /**
     * The same job without the till — a driver who drops parcels off and touches no cash.
     *
     * @return array<string, string>
     */
    private function courier(): array
    {
        return $this->tokenFor([
            PermissionName::ViewOrders,
            PermissionName::MarkOrdersDelivered,
            PermissionName::SettleOrders,
        ]);
    }

    /**
     * @param  list<PermissionName>  $permissions
     * @return array<string, string>
     */
    private function tokenFor(array $permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (PermissionName $p) => $p->value, $permissions));

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function show(array $headers, Order $order): TestResponse
    {
        return $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");
    }

    /**
     * **`post`, not `postJson`** — the same call either way.
     *
     * A move may now carry a file, so the app sends the whole bag as multipart whenever one is
     * attached. Laravel reads a multipart body exactly as it reads a JSON one, and testing
     * through the format the app actually uses is what proves the numbers still validate when
     * they arrive as strings.
     */
    private function move(array $headers, Order $order, OrderStatus $to, array $fields = []): TestResponse
    {
        return $this->withHeaders($headers)->post(
            "/api/v1/orders/{$order->id}/status",
            ['status' => $to->value, 'fields' => $fields],
        );
    }

    /** A believable الواصل: a small PDF, the shape a bank actually sends. */
    private function receipt(): UploadedFile
    {
        return UploadedFile::fake()->create('waseel.pdf', 120, 'application/pdf');
    }

    /** An order waiting at the counter for a customer who owes what is left of it. */
    private function waitingOrder(string $grandTotal, string $paid = '0.00'): Order
    {
        return $this->orderAt(OrderStatus::OfficePickup, $grandTotal, $paid);
    }

    /** An order already handed over, with its money still to come back. */
    private function deliveredOrder(string $grandTotal, string $paid = '0.00'): Order
    {
        return $this->orderAt(OrderStatus::Delivered, $grandTotal, $paid);
    }

    /**
     * An order somewhere on the line, with whatever has been paid on it **backed by a real
     * ledger row**.
     *
     * The neighbouring suites write `paid_amount` on its own, which is the right shortcut for
     * testing a guard that only reads the figure. It is the wrong one here: recording a payment
     * recalculates that column from the entries, so a balance with nothing behind it is silently
     * replaced by this move's own figure — a deposit of 100 followed by 150 at the counter would
     * total 150 and the order would look short by exactly the deposit.
     */
    private function orderAt(OrderStatus $status, string $grandTotal, string $paid): Order
    {
        $order = Order::factory()->status($status)->create([
            'grand_total' => $grandTotal,
            'paid_amount' => $paid,
        ]);

        if (bccomp($paid, '0', 2) > 0) {
            OrderPayment::factory()->forOrder($order)->amount($paid)->create();
        }

        return $order;
    }

    /** Entries on the ledger, deposits seeded by the arrangement included. */
    private function paymentCount(Order $order): int
    {
        return OrderPayment::query()->where('order_id', $order->getKey())->count();
    }

    /** The one transition out of the list, whatever position the map put it in. */
    private function transition(TestResponse $response, OrderStatus $target): ?array
    {
        foreach ($response->json('data.available_transitions') as $transition) {
            if ($transition['status'] === $target->value) {
                return $transition;
            }
        }

        return null;
    }

    private function field(?array $transition, string $key): ?array
    {
        foreach ($transition['fields'] ?? [] as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }

    // ─────────────────────────── what the counter is asked for ───────────────────────────

    public function test_handing_the_bags_over_offers_a_box_for_the_money_taken_with_them(): void
    {
        // Arrange
        $order = $this->waitingOrder('250.00');
        $headers = $this->cashier();

        // Act
        $receiving = $this->transition($this->show($headers, $order), OrderStatus::Delivered);
        $amount = $this->field($receiving, 'payment_amount');

        // Assert — offered, never demanded: an order paid in full weeks ago is handed over with
        // the box left alone. The ceiling is what is still owed, so the field refuses an
        // overpayment before the request is even sent, and the hint says the figure out loud.
        $this->assertNotNull($amount);
        $this->assertSame('number', $amount['type']);
        $this->assertSame('المبلغ المقبوض', $amount['label']);
        $this->assertFalse($amount['required']);
        $this->assertEquals(250.0, $amount['max']);
        $this->assertSame('المتبقي 250', $amount['hint']);
        $this->assertNull($amount['value']);
    }

    public function test_the_method_is_asked_for_beside_it_and_opens_on_cash(): void
    {
        // Arrange
        $order = $this->waitingOrder('250.00');
        $headers = $this->cashier();

        // Act
        $receiving = $this->transition($this->show($headers, $order), OrderStatus::Delivered);
        $method = $this->field($receiving, 'payment_method');

        // Assert — the ledger will not take an entry without one, so it is asked here rather
        // than guessed later. Cash is filled in because cash is what a counter takes: agreeing
        // costs no taps, and disagreeing costs one.
        $this->assertNotNull($method);
        $this->assertSame('payment_method', $method['type']);
        $this->assertSame('طريقة الدفع', $method['label']);
        $this->assertSame('cash', $method['value']);
        $this->assertSame('payment_amount', $method['required_with']);
    }

    public function test_every_method_the_business_uses_is_offered_here(): void
    {
        // Arrange
        $order = $this->waitingOrder('250.00');
        $headers = $this->cashier();

        // Act
        $method = $this->field(
            $this->transition($this->show($headers, $order), OrderStatus::Delivered),
            'payment_method',
        );

        // Assert — all four, «حوالة» included. It was left off while this screen could take no
        // files, which made the counter a poorer place to record a payment than the payments
        // screen for no reason a person could see. The file field below it is what let it back.
        $offered = array_column($method['options'], 'value');

        $this->assertSame(PaymentMethod::values(), $offered);
        $this->assertSame('كاش', $method['options'][0]['label']);
        $this->assertSame('حوالة', $method['options'][1]['label']);
    }

    public function test_the_receipt_is_offered_with_the_money_and_demanded_only_by_a_transfer(): void
    {
        // Arrange
        $order = $this->waitingOrder('250.00');
        $headers = $this->cashier();

        // Act
        $receipt = $this->field(
            $this->transition($this->show($headers, $order), OrderStatus::Delivered),
            'payment_receipt',
        );

        // Assert — one field, two jobs. Obligatory for «حوالة», whose only proof is a document
        // the customer sends; offered for everything else, because somebody holding the paper
        // for a card payment should never be told we have nowhere to put it.
        //
        // The condition travels *with* the field, so the app greys its button on the same rule
        // the endpoint enforces rather than keeping a copy of «حوالة تحتاج واصلاً» in Dart.
        $this->assertNotNull($receipt);
        $this->assertSame('file', $receipt['type']);
        $this->assertFalse($receipt['required']);
        $this->assertSame(
            ['key' => 'payment_method', 'value' => PaymentMethod::BankTransfer->value],
            $receipt['required_if'],
        );
    }

    public function test_the_receipt_carries_what_the_server_will_accept(): void
    {
        // Arrange
        $order = $this->waitingOrder('250.00');
        $headers = $this->cashier();

        // Act
        $receipt = $this->field(
            $this->transition($this->show($headers, $order), OrderStatus::Delivered),
            'payment_receipt',
        );

        // Assert — read off `media.payment_receipts`, so the app refuses a doomed file before
        // pushing it over a mobile connection, and cannot drift from what the endpoint takes.
        $this->assertSame(config('media.payment_receipts.mimes'), $receipt['extensions']);
        $this->assertSame(
            (int) config('media.payment_receipts.max_kilobytes'),
            $receipt['max_kilobytes'],
        );
    }

    public function test_a_transfer_without_its_receipt_is_refused_and_nothing_moves(): void
    {
        // Arrange
        Storage::fake('local');
        $order = $this->waitingOrder('250.00');
        $headers = $this->cashier();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Delivered, [
            'payment_amount' => '200.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
        ]);

        // Assert — a disputed transfer with no document is one person's word against another's,
        // which is why this rule exists in three places. This is the readable one.
        $response->assertStatus(422)->assertJsonValidationErrors('fields.payment_receipt');
        $this->assertSame(OrderStatus::OfficePickup, $order->fresh()->status);
        $this->assertSame(0, $this->paymentCount($order));
    }

    public function test_a_transfer_with_its_receipt_is_recorded_with_the_file_on_it(): void
    {
        // Arrange
        Storage::fake('local');
        $order = $this->waitingOrder('250.00');
        $headers = $this->cashier();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Delivered, [
            'payment_amount' => '200.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
            'payment_receipt' => $this->receipt(),
        ]);

        // Assert — the entry and its الواصل land together, exactly as they do from the payments
        // screen: the same action stores the file and writes the five columns describing it.
        $response->assertOk()->assertJsonPath('data.status', 'delivered');

        $payment = OrderPayment::query()->where('order_id', $order->id)->sole();

        $this->assertSame(PaymentMethod::BankTransfer, $payment->method);
        $this->assertSame('waseel.pdf', $payment->receipt_original_filename);
        $this->assertNotNull($payment->receipt_path);
        Storage::disk($payment->receipt_disk)->assertExists($payment->receipt_path);
    }

    public function test_a_card_payment_may_carry_its_slip_without_being_made_to(): void
    {
        // Arrange
        Storage::fake('local');
        $order = $this->waitingOrder('250.00');
        $headers = $this->cashier();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Delivered, [
            'payment_amount' => '200.00',
            'payment_method' => PaymentMethod::BankCard->value,
            'payment_receipt' => UploadedFile::fake()->image('slip.jpg'),
        ]);

        // Assert — the slip out of the machine is worth keeping and nothing obliges it.
        $response->assertOk();

        $payment = OrderPayment::query()->where('order_id', $order->id)->sole();

        $this->assertSame(PaymentMethod::BankCard, $payment->method);
        $this->assertSame('slip.jpg', $payment->receipt_original_filename);
    }

    public function test_a_file_the_server_will_not_store_is_refused_at_the_field(): void
    {
        // Arrange
        Storage::fake('local');
        $order = $this->waitingOrder('250.00');
        $headers = $this->cashier();

        // Act — an executable renamed is still an executable, and the mime is sniffed.
        $response = $this->move($headers, $order, OrderStatus::Delivered, [
            'payment_amount' => '200.00',
            'payment_method' => PaymentMethod::Cash->value,
            'payment_receipt' => UploadedFile::fake()->create('waseel.exe', 10, 'application/x-msdownload'),
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('fields.payment_receipt');
        $this->assertSame(0, $this->paymentCount($order));
    }

    public function test_a_receipt_with_no_money_beside_it_records_nothing(): void
    {
        // Arrange — a file attached and the amount left alone. Nothing was collected, so there
        // is no entry to hang it on.
        Storage::fake('local');
        $order = $this->waitingOrder('250.00');
        $headers = $this->cashier();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Delivered, [
            'payment_method' => PaymentMethod::Cash->value,
            'payment_receipt' => $this->receipt(),
        ]);

        // Assert — the move goes through and the ledger stays as it was. A file stored against
        // no entry would be an orphan nothing could ever show.
        $response->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertSame(0, $this->paymentCount($order));
    }

    public function test_an_order_with_nothing_left_to_pay_is_not_asked_for_money(): void
    {
        // Arrange — paid in full when it was ordered.
        $order = $this->waitingOrder('250.00', paid: '250.00');
        $headers = $this->cashier();

        // Act
        $receiving = $this->transition($this->show($headers, $order), OrderStatus::Delivered);

        // Assert — a box whose every value would be refused is worse than no box.
        $this->assertNull($this->field($receiving, 'payment_amount'));
        $this->assertNull($this->field($receiving, 'payment_method'));
    }

    public function test_somebody_who_may_not_record_payments_is_not_offered_the_box(): void
    {
        // Arrange — a driver drops the parcel off; the till is not their business.
        $order = $this->waitingOrder('250.00');
        $headers = $this->courier();

        // Act
        $receiving = $this->transition($this->show($headers, $order), OrderStatus::Delivered);

        // Assert — the move is still theirs to make, and only the money is withheld.
        $this->assertNotNull($receiving);
        $this->assertNull($this->field($receiving, 'payment_amount'));
    }

    public function test_a_payment_posted_by_somebody_without_the_grant_is_refused(): void
    {
        // Arrange
        $order = $this->waitingOrder('250.00');
        $headers = $this->courier();

        // Act — the form never offered it, so the endpoint has never heard of it either.
        $response = $this->move($headers, $order, OrderStatus::Delivered, [
            'payment_amount' => '200.00',
            'payment_method' => 'cash',
        ]);

        // Assert — refused whole: the status did not move on a payload the server rejected.
        $response->assertStatus(422);
        $this->assertSame(OrderStatus::OfficePickup, $order->fresh()->status);
        $this->assertSame(0, $this->paymentCount($order));
    }

    // ─────────────────────────── what the box actually does ───────────────────────────

    public function test_the_money_typed_at_the_counter_becomes_a_ledger_entry(): void
    {
        // Arrange
        $order = $this->waitingOrder('250.00');
        $headers = $this->cashier();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Delivered, [
            'payment_amount' => '200.00',
            'payment_method' => 'cash',
        ]);

        // Assert — a real row of the same shape the payments screen writes, and «المدفوع» moves
        // with it. Nothing is invented: this is the figure a person typed.
        $response->assertOk()->assertJsonPath('data.status', 'delivered');

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'type' => OrderPaymentType::Payment->value,
            'method' => PaymentMethod::Cash->value,
            'amount' => '200.00',
        ]);

        $moved = $order->fresh();
        $this->assertSame('200.00', $moved->paid_amount);
        $this->assertSame('50.00', $moved->remainingAmount());
    }

    public function test_the_entry_is_attributed_to_whoever_took_the_money(): void
    {
        // Arrange
        $order = $this->waitingOrder('250.00');
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewOrders->value,
            PermissionName::MarkOrdersDelivered->value,
            PermissionName::RecordOrderPayments->value,
        ]);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        // Act
        $this->move($headers, $order, OrderStatus::Delivered, [
            'payment_amount' => '250.00',
            'payment_method' => 'libyana',
        ]);

        // Assert — the same rule the payments endpoint keeps: nobody's name goes on a collection
        // but the signed-in person's, and no payload can say otherwise.
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'recorded_by' => $user->id,
            'method' => PaymentMethod::Libyana->value,
        ]);
    }

    public function test_an_empty_box_moves_the_order_and_records_nothing(): void
    {
        // Arrange — the ordinary case: the customer paid when they ordered.
        $order = $this->waitingOrder('250.00', paid: '250.00');
        $headers = $this->cashier();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Delivered);

        // Assert
        $response->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertSame(1, $this->paymentCount($order), 'only the deposit the arrangement seeded');
    }

    public function test_more_than_is_owed_is_refused_and_the_order_stays_put(): void
    {
        // Arrange
        $order = $this->waitingOrder('250.00', paid: '100.00');
        $headers = $this->cashier();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Delivered, [
            'payment_amount' => '200.00',
            'payment_method' => 'cash',
        ]);

        // Assert — the ceiling on the field catches it first, and the move dies with it: a
        // status that advanced while its money was refused would be the worst of both.
        $response->assertStatus(422)->assertJsonValidationErrors('fields.payment_amount');
        $this->assertSame(OrderStatus::OfficePickup, $order->fresh()->status);
        $this->assertSame(1, $this->paymentCount($order), 'only the deposit the arrangement seeded');
    }

    public function test_an_amount_without_a_method_is_refused(): void
    {
        // Arrange
        $order = $this->waitingOrder('250.00');
        $headers = $this->cashier();

        // Act — the app fills the method in, so this is a payload written by hand.
        $response = $this->move($headers, $order, OrderStatus::Delivered, [
            'payment_amount' => '200.00',
        ]);

        // Assert — the ledger's own shape rule, said in Arabic at the field rather than as a
        // constraint violation five layers down.
        $response->assertStatus(422)->assertJsonValidationErrors('fields.payment_method');
        $this->assertDatabaseCount('order_payments', 0);
    }

    // ──────────────────── «تم التسوية» — one money box, and it is this one ────────────────────

    public function test_settling_asks_for_the_money_and_opens_holding_what_is_still_owed(): void
    {
        // Arrange
        $order = $this->deliveredOrder('250.00', paid: '100.00');
        $headers = $this->cashier();

        // Act
        $settling = $this->transition($this->show($headers, $order), OrderStatus::Settled);
        $amount = $this->field($settling, 'payment_amount');

        // Assert — settling *means* the money came back, and nearly always all of it, so the box
        // opens holding the remainder and agreeing costs a tap. This is the field «المبلغ
        // المستلم» used to be: it asked the same question and answered none of it, because it
        // wrote a column nothing added up.
        $this->assertNotNull($amount);
        $this->assertSame('150', $amount['value']);
        $this->assertEquals(150.0, $amount['max']);
        $this->assertNull($this->field($settling, 'collected_amount'));
    }

    public function test_the_money_typed_at_settlement_pays_the_order_off_in_the_same_move(): void
    {
        // Arrange — the case that had no answer: the guard refused the move until a payment was
        // recorded, and the only place to record one was another screen.
        $order = $this->deliveredOrder('250.00', paid: '100.00');
        $headers = $this->cashier();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Settled, [
            'payment_amount' => '150.00',
            'payment_method' => 'cash',
        ]);

        // Assert — one tap: the entry lands, the debt closes, and the guard that reads it is
        // satisfied by what this same request just wrote.
        $response->assertOk()->assertJsonPath('data.status', 'settled');

        $settled = $order->fresh();
        $this->assertSame('250.00', $settled->paid_amount);
        $this->assertSame('0.00', $settled->remainingAmount());
        $this->assertNotNull($settled->settled_at);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'amount' => '150.00',
            'type' => OrderPaymentType::Payment->value,
        ]);
    }

    public function test_a_settlement_that_still_leaves_a_debt_takes_its_payment_back_with_it(): void
    {
        // Arrange
        $order = $this->deliveredOrder('250.00', paid: '0.00');
        $headers = $this->cashier();

        // Act — 100 of 250 handed over, and the accountant tries to close the order on it.
        $response = $this->move($headers, $order, OrderStatus::Settled, [
            'payment_amount' => '100.00',
            'payment_method' => 'cash',
        ]);

        // Assert — refused naming the remainder, and **the entry is rolled back with it**. One
        // transaction: a payment that survived a move it was part of would leave the ledger
        // describing a settlement that never happened.
        $response->assertStatus(422);
        $this->assertStringContainsString('150.00', $response->json('message'));

        $unmoved = $order->fresh();
        $this->assertSame(OrderStatus::Delivered, $unmoved->status);
        $this->assertSame('0.00', $unmoved->paid_amount);
        $this->assertSame(0, $this->paymentCount($order));
    }

    public function test_an_order_already_paid_off_settles_with_no_box_to_fill(): void
    {
        // Arrange
        $order = $this->deliveredOrder('250.00', paid: '250.00');
        $headers = $this->cashier();

        // Act
        $settling = $this->transition($this->show($headers, $order), OrderStatus::Settled);
        $response = $this->move($headers, $order, OrderStatus::Settled);

        // Assert
        $this->assertNull($this->field($settling, 'payment_amount'));
        $response->assertOk()->assertJsonPath('data.status', 'settled');
        $this->assertSame(1, $this->paymentCount($order), 'only the deposit the arrangement seeded');
    }
}
