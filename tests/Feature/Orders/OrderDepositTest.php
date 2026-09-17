<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Delivery\Enums\FulfilmentType;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderPaymentType;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * العربون — ما طُلب، وما قيل إنه دُفع، ومَن تأكّد أنه وصل.
 *
 * **Three facts by three different routes, and the suite is arranged around keeping them apart.**
 * «انتظار العربون» records an arrangement and writes nothing to the ledger. «عربون مدفوع» is a
 * claim, made on the customer's word so the job can start, which *may* carry a real payment.
 * `is_deposit_received` is a second employee's confirmation, made whenever they have checked the
 * account — never during the status change, and never by the person who made the claim.
 *
 * **And none of it gates anything**: the last test walks an order with an unconfirmed deposit all
 * the way to «تم التسوية» without a single refusal, because bookkeeping that stops a press is
 * bookkeeping nobody does.
 *
 * See Docs/orders/ORDER-DEPOSIT-PLAN.md. Arrange - Act - Assert throughout.
 */
class OrderDepositTest extends TestCase
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
     * @param  list<PermissionName>  $permissions
     * @return array{0: User, 1: array<string, string>}
     */
    private function userWith(array $permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (PermissionName $p) => $p->value, $permissions));

        return [$user, ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken]];
    }

    /**
     * The counter: takes orders, asks for the عربون, and is told when it arrives. Holds the till
     * grant too, because the money is often handed over across the same desk.
     *
     * @return array{0: User, 1: array<string, string>}
     */
    private function clerk(): array
    {
        return $this->userWith([
            PermissionName::ViewOrders,
            PermissionName::MoveOrderToAwaitingDeposit,
            PermissionName::MoveOrderToDepositPaid,
            PermissionName::RecordOrderPayments,
            PermissionName::MoveOrderToReadyToPrint,
        ]);
    }

    /**
     * The books: checks the account and confirms the money is really there. **Not the person who
     * made the claim** — that is the whole rule.
     *
     * @return array{0: User, 1: array<string, string>}
     */
    private function accountant(): array
    {
        return $this->userWith([
            PermissionName::ViewOrders,
            PermissionName::ConfirmDepositReceipt,
            PermissionName::RecordOrderPayments,
            PermissionName::ReverseOrderPayments,
        ]);
    }

    /**
     * **`post`, not `postJson`** — the app sends a status change as multipart whenever a file
     * rides along, so the numbers arrive as strings either way. Testing through that shape is
     * what proves they still validate.
     */
    private function move(array $headers, Order $order, OrderStatus $to, array $fields = []): TestResponse
    {
        return $this->withHeaders($headers)->post(
            "/api/v1/orders/{$order->id}/status",
            ['status' => $to->value, 'fields' => $fields],
        );
    }

    private function confirm(array $headers, Order $order, bool $received): TestResponse
    {
        return $this->withHeaders($headers)->patchJson(
            "/api/v1/orders/{$order->id}/deposit-receipt",
            ['received' => $received],
        );
    }

    /**
     * A freshly taken order, priced, with nothing paid on it.
     *
     * **`stock_deducted_at` is pre-set**, which is a shortcut with a reason: this suite is about
     * money, and an order whose stock has not left yet would make every move past «جاهزة
     * للطباعة» ask for a warehouse and a weight per line. Those rules have their own suite
     * (`OrderReadyToPrintTest`), and standing them up here would test them twice while making
     * the deposit assertions harder to read.
     */
    private function newOrder(string $grandTotal = '1000.00'): Order
    {
        return Order::factory()->status(OrderStatus::New)->create([
            'grand_total' => $grandTotal,
            'stock_deducted_at' => now()->subDay(),
        ]);
    }

    /** That order, parked waiting on a عربون of [$amount]. */
    private function awaitingDeposit(array $headers, string $amount = '250.00'): Order
    {
        $order = $this->newOrder();

        $this->move($headers, $order, OrderStatus::AwaitingDeposit, [
            'deposit_amount' => $amount,
            'deposit_method' => PaymentMethod::Cash->value,
        ])->assertOk();

        return $order->refresh();
    }

    // ── «انتظار العربون» — an arrangement, and nothing in the ledger ─────────────────────────

    public function test_parking_an_order_records_the_figure_and_the_method_and_no_payment(): void
    {
        // Arrange
        [, $headers] = $this->clerk();
        $order = $this->newOrder();

        // Act
        $response = $this->move($headers, $order, OrderStatus::AwaitingDeposit, [
            'deposit_amount' => '250',
            'deposit_method' => PaymentMethod::BankTransfer->value,
        ]);

        // Assert — the arrangement is on the order…
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.status', OrderStatus::AwaitingDeposit->value)
            ->assertJsonPath('data.status_label', 'انتظار العربون')
            ->assertJsonPath('data.deposit_expected_amount', '250.00')
            ->assertJsonPath('data.deposit_expected_method', PaymentMethod::BankTransfer->value)
            ->assertJsonPath('data.is_deposit_received', false);

        // …and nothing at all is in the ledger: no money has moved.
        $order->refresh();
        $this->assertSame('0.00', (string) $order->paid_amount);
        $this->assertDatabaseCount('order_payments', 0);
        $this->assertNull($order->deposit_paid_at);
    }

    public function test_the_figure_is_required_and_must_be_a_real_amount(): void
    {
        // Arrange
        [, $headers] = $this->clerk();
        $order = $this->newOrder();

        // Act
        $missing = $this->move($headers, $order, OrderStatus::AwaitingDeposit);
        $zero = $this->move($headers, $order, OrderStatus::AwaitingDeposit, ['deposit_amount' => '0']);
        $overTotal = $this->move($headers, $order, OrderStatus::AwaitingDeposit, [
            'deposit_amount' => '5000',
        ]);

        // Assert — an order parked with no figure on it says only «موقوفة», and the next person
        // to open it cannot learn what was agreed. A عربون larger than the whole invoice is a
        // typo every time.
        $missing->assertStatus(422);
        $this->assertSame(
            ['قيمة العربون مطلوب'],
            $missing->json('errors')['fields.deposit_amount'],
        );
        $zero->assertStatus(422);
        $overTotal->assertStatus(422);

        $this->assertSame(OrderStatus::New, $order->refresh()->status);
    }

    public function test_the_boxes_open_on_what_was_agreed_last_time(): void
    {
        // Arrange — an order parked, paid, and sent back to wait again.
        [, $clerk] = $this->clerk();
        $order = $this->awaitingDeposit($clerk, '300.00');
        $this->move($clerk, $order, OrderStatus::DepositPaid)->assertOk();
        $this->move($clerk, $order->refresh(), OrderStatus::AwaitingDeposit, [
            'deposit_amount' => '300',
            'deposit_method' => PaymentMethod::Cash->value,
        ])->assertOk();

        // Act
        $response = $this->withHeaders($clerk)->getJson("/api/v1/orders/{$order->id}");

        // Assert — the commonest reason to be back here is that the money did not arrive, not
        // that the arrangement changed, so the boxes open holding it.
        $fields = collect($response->json('data.available_transitions'))
            ->firstWhere('status', OrderStatus::DepositPaid->value);

        $this->assertNotNull($fields);
        // «300», not «300.00»: TransitionField::number() trims what it suggests — the scale
        // belongs to the column the figure was read out of, not to a box somebody is about to
        // agree with. See DecimalText, which arrived with the partial-delivery work.
        $this->assertSame(
            '300',
            collect($response->json('data.available_transitions'))
                ->firstWhere('status', OrderStatus::DepositPaid->value)['fields'][0]['value'],
        );
    }

    // ── طلبية بلا قيمة — الطريق نفسه، بلا مال ────────────────────────────────────────────────

    public function test_an_order_with_nothing_to_pay_is_asked_for_nothing(): void
    {
        // Arrange — الخصم ابتلع الفاتورة: طلبية إجماليها صفر.
        [, $clerk] = $this->clerk();
        $order = $this->newOrder('0.00');

        // Act
        $listed = $this->withHeaders($clerk)->getJson("/api/v1/orders/{$order->id}");
        $withAFigure = $this->move($clerk, $order, OrderStatus::AwaitingDeposit, [
            'deposit_amount' => '0',
        ]);

        // Assert — a box whose only possible answer is «0» is a box that asks nothing, so none is
        // drawn; and the endpoint refuses the key it never offered, like every other move.
        $keys = array_column(
            collect($listed->json('data.available_transitions'))
                ->firstWhere('status', OrderStatus::AwaitingDeposit->value)['fields'],
            'key',
        );

        $this->assertNotContains('deposit_amount', $keys);
        $this->assertNotContains('deposit_method', $keys);
        $withAFigure->assertStatus(422);
        $this->assertSame(OrderStatus::New, $order->refresh()->status);
    }

    public function test_an_order_with_nothing_to_pay_walks_the_road_all_the_same(): void
    {
        // Arrange
        [, $clerk] = $this->clerk();
        $order = $this->newOrder('0.00');

        // Act
        $parked = $this->move($clerk, $order, OrderStatus::AwaitingDeposit);
        $claimed = $this->move($clerk, $order->refresh(), OrderStatus::DepositPaid);

        // Assert — «عربون مدفوع» is the warehouse's own work list, and an order that cannot reach
        // it is an order nobody there will ever see. So the road is walked with a zero written on
        // it rather than with nothing: the next person to open the order reads «العربون 0» and
        // knows it was never money, instead of an empty column they have to guess about.
        $parked->assertOk()
            ->assertJsonPath('data.status', OrderStatus::AwaitingDeposit->value)
            ->assertJsonPath('data.deposit_expected_amount', '0.00')
            ->assertJsonPath('data.deposit_expected_method', null);
        $claimed->assertOk()->assertJsonPath('data.status', OrderStatus::DepositPaid->value);

        // And not one row of money for a move that moved none.
        $order->refresh();
        $this->assertNotNull($order->deposit_paid_at);
        $this->assertSame('0.00', (string) $order->paid_amount);
        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_a_deposit_of_nothing_is_not_the_accountants_to_confirm(): void
    {
        // Arrange
        [, $clerk] = $this->clerk();
        [, $books] = $this->accountant();
        $order = $this->newOrder('0.00');
        $this->move($clerk, $order, OrderStatus::AwaitingDeposit)->assertOk();
        $this->move($clerk, $order->refresh(), OrderStatus::DepositPaid)->assertOk();

        // Act
        $ticked = $this->confirm($books, $order->refresh(), true);
        $listed = $this->withHeaders($books)->getJson("/api/v1/orders/{$order->id}");

        // Assert — «رأيتُ العربون في الحساب» about nothing at all is a row in the accountant's
        // queue that no money explains. The status is the warehouse's signal; it was never a
        // claim that anybody paid anything.
        $ticked->assertStatus(422)
            ->assertJsonPath('message', 'لا يوجد عربون على هذه الطلبية لتأكيد استلامه');
        $listed->assertOk()
            ->assertJsonPath('data.can_confirm_deposit', false)
            ->assertJsonPath('data.awaits_deposit_confirmation', false);
        $this->assertFalse($order->refresh()->is_deposit_received);
    }

    // ── «عربون مدفوع» — a claim, with or without money behind it ─────────────────────────────

    public function test_a_deposit_taken_at_the_counter_becomes_an_ordinary_payment(): void
    {
        // Arrange
        [$user, $headers] = $this->clerk();
        $order = $this->awaitingDeposit($headers, '250.00');

        // Act
        $response = $this->move($headers, $order, OrderStatus::DepositPaid, [
            'payment_amount' => '250',
            'payment_method' => PaymentMethod::Cash->value,
        ]);

        // Assert — one entry, of the same type the payments screen writes, counted the same way.
        $response->assertOk()
            ->assertJsonPath('data.status', OrderStatus::DepositPaid->value)
            ->assertJsonPath('data.status_label', 'عربون مدفوع')
            ->assertJsonPath('data.paid_amount', '250.00')
            ->assertJsonPath('data.remaining_amount', '750.00');

        $order->refresh();
        $this->assertDatabaseCount('order_payments', 1);
        $this->assertSame(OrderPaymentType::Payment, OrderPayment::firstOrFail()->type);
        $this->assertSame((int) $order->deposit_payment_id, (int) OrderPayment::firstOrFail()->id);
        $this->assertNotNull($order->deposit_paid_at);
        $this->assertSame((int) $user->id, (int) $order->deposit_claimed_by);

        // **And the tick is still false.** The status is the counter's claim; nobody has checked.
        $this->assertFalse($order->is_deposit_received);
    }

    public function test_the_claim_may_be_made_with_no_money_recorded_at_all(): void
    {
        // Arrange — the حوالة is on its way and the customer wants the job started.
        [, $headers] = $this->clerk();
        $order = $this->awaitingDeposit($headers);

        // Act
        $response = $this->move($headers, $order, OrderStatus::DepositPaid);

        // Assert — accepted, and honest about what it knows: claimed, unpaid, unconfirmed.
        $response->assertOk()
            ->assertJsonPath('data.status', OrderStatus::DepositPaid->value)
            ->assertJsonPath('data.paid_amount', '0.00')
            ->assertJsonPath('data.is_deposit_received', false)
            ->assertJsonPath('data.awaits_deposit_confirmation', true);

        $this->assertDatabaseCount('order_payments', 0);
        $this->assertNull($order->refresh()->deposit_payment_id);
    }

    public function test_the_money_box_is_withheld_from_somebody_without_the_till_and_the_move_still_works(): void
    {
        // Arrange — a clerk who may move orders but touches no cash.
        [, $tillless] = $this->userWith([
            PermissionName::ViewOrders,
            PermissionName::MoveOrderToAwaitingDeposit,
            PermissionName::MoveOrderToDepositPaid,
        ]);
        $order = $this->awaitingDeposit($tillless);

        // Act
        $listed = $this->withHeaders($tillless)->getJson("/api/v1/orders/{$order->id}");
        $moved = $this->move($tillless, $order, OrderStatus::DepositPaid);

        // Assert — the field is hidden, not the move: withholding the button would leave the
        // order stuck on a permission its holder does not need for the job they are doing.
        $keys = collect($listed->json('data.available_transitions'))
            ->firstWhere('status', OrderStatus::DepositPaid->value)['fields'];

        $this->assertNotContains('payment_amount', array_column($keys, 'key'));
        $moved->assertOk()->assertJsonPath('data.status', OrderStatus::DepositPaid->value);
    }

    public function test_a_transfer_without_its_receipt_is_refused(): void
    {
        // Arrange
        [, $headers] = $this->clerk();
        $order = $this->awaitingDeposit($headers);

        // Act
        $response = $this->move($headers, $order, OrderStatus::DepositPaid, [
            'payment_amount' => '250',
            'payment_method' => PaymentMethod::BankTransfer->value,
        ]);

        // Assert — the same rule the payments screen states, on the same file, here too: a
        // disputed حوالة with no paper is one person's word against another's.
        $response->assertStatus(422);
        $this->assertSame(OrderStatus::AwaitingDeposit, $order->refresh()->status);
        $this->assertDatabaseCount('order_payments', 0);
    }

    // ── walking it back ──────────────────────────────────────────────────────────────────────

    public function test_walking_the_claim_back_reverses_the_entry_the_move_wrote(): void
    {
        // Arrange
        [, $headers] = $this->clerk();
        $order = $this->awaitingDeposit($headers, '250.00');
        $this->move($headers, $order, OrderStatus::DepositPaid, [
            'payment_amount' => '250',
            'payment_method' => PaymentMethod::Cash->value,
        ])->assertOk();

        // Act
        $response = $this->move($headers, $order->refresh(), OrderStatus::AwaitingDeposit, [
            'deposit_amount' => '250',
            'deposit_method' => PaymentMethod::Cash->value,
            'reason' => 'الحوالة لم تصل',
        ]);

        // Assert — the money is off the order, and the ledger says why rather than losing a row.
        $response->assertOk()->assertJsonPath('data.paid_amount', '0.00');

        $this->assertDatabaseCount('order_payments', 2);
        $this->assertDatabaseHas('order_payments', [
            'type' => OrderPaymentType::Reversal->value,
            'notes' => 'الحوالة لم تصل',
        ]);

        // And the claim itself is gone, so the order is not in the accountant's queue: it is
        // waiting on money, which is what its status says.
        $order->refresh();
        $this->assertNull($order->deposit_paid_at);
        $this->assertNull($order->deposit_claimed_by);
        $this->assertNull($order->deposit_payment_id);
        $this->assertFalse($order->awaitsDepositConfirmation());
    }

    public function test_walking_back_a_claim_that_recorded_nothing_touches_no_ledger_row(): void
    {
        // Arrange — the ordinary case: claimed on the customer's word, with the deposit typed in
        // on the payments screen instead.
        [, $clerk] = $this->clerk();
        [, $books] = $this->accountant();
        $order = $this->awaitingDeposit($clerk);
        $this->move($clerk, $order, OrderStatus::DepositPaid)->assertOk();

        $this->withHeaders($books)->postJson("/api/v1/orders/{$order->id}/payments", [
            'amount' => '250',
            'method' => PaymentMethod::Cash->value,
        ])->assertCreated();

        // Act
        $response = $this->move($clerk, $order->refresh(), OrderStatus::AwaitingDeposit, [
            'deposit_amount' => '250',
            'deposit_method' => PaymentMethod::Cash->value,
        ]);

        // Assert — **somebody else's entry is not this move's to undo.** The status change did
        // not write it, and a status change that reversed a payment it never made would be the
        // same lie in the other direction.
        $response->assertOk();
        $this->assertDatabaseCount('order_payments', 1);
        $this->assertSame('250.00', (string) $order->refresh()->paid_amount);
    }

    public function test_cancelling_leaves_the_deposit_exactly_where_it_is(): void
    {
        // Arrange — an order written off with its عربون already in the ledger.
        [, $clerk] = $this->clerk();
        [, $canceller] = $this->userWith([
            PermissionName::ViewOrders,
            PermissionName::CancelOrders,
            PermissionName::ReverseOrderPayments,
        ]);
        $order = $this->awaitingDeposit($clerk, '250.00');
        $this->move($clerk, $order, OrderStatus::DepositPaid, [
            'payment_amount' => '250',
            'payment_method' => PaymentMethod::Cash->value,
        ])->assertOk();

        // Act
        $response = $this->move($canceller, $order->refresh(), OrderStatus::Cancelled, [
            'reason' => 'الزبون تراجع',
        ]);

        // Assert — **nothing at all happens to the money.** The عربون was taken, and whether any
        // of it goes back is a decision somebody makes afterwards, not something a cancellation
        // performs on its own: a status change that quietly handed cash back would be inventing
        // the one entry the ledger is built never to invent. The walk-back to «انتظار العربون»
        // is the only move in this feature that touches an entry, and this is not it.
        $response->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value)
            ->assertJsonPath('data.paid_amount', '250.00');

        $this->assertDatabaseCount('order_payments', 1);
        $this->assertDatabaseMissing('order_payments', ['type' => OrderPaymentType::Reversal->value]);
        $this->assertDatabaseMissing('order_payments', ['type' => OrderPaymentType::Refund->value]);

        // And the arrangement is still readable: «كم كان العربون على الطلبية التي ألغيناها؟»
        $order->refresh();
        $this->assertSame('250.00', (string) $order->deposit_expected_amount);
        $this->assertNotNull($order->deposit_paid_at);
    }

    // ── the tick: a second person, afterwards ────────────────────────────────────────────────

    public function test_the_deposit_is_confirmed_later_by_somebody_else(): void
    {
        // Arrange — claimed at the counter, the order already in production, and three days on
        // the accountant checks the account. **This is the case the whole design exists for.**
        [, $clerk] = $this->clerk();
        [$books, $booksHeaders] = $this->accountant();
        $order = $this->awaitingDeposit($clerk);
        $this->move($clerk, $order, OrderStatus::DepositPaid)->assertOk();
        $this->move($clerk, $order->refresh(), OrderStatus::ReadyToPrint)->assertOk();

        // Act
        $response = $this->confirm($booksHeaders, $order->refresh(), true);

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'تم تأكيد استلام العربون')
            ->assertJsonPath('data.is_deposit_received', true)
            ->assertJsonPath('data.awaits_deposit_confirmation', false)
            // The order has not moved, and the tick did not move it.
            ->assertJsonPath('data.status', OrderStatus::ReadyToPrint->value);

        $order->refresh();
        $this->assertTrue($order->is_deposit_received);
        $this->assertNotNull($order->deposit_confirmed_at);
        $this->assertSame((int) $books->id, (int) $order->deposit_confirmed_by);
    }

    public function test_the_person_who_claimed_the_deposit_may_not_confirm_it(): void
    {
        // Arrange — one user holding *both* grants, which is exactly the case a rule kept in the
        // role configuration would miss.
        [, $headers] = $this->userWith([
            PermissionName::ViewOrders,
            PermissionName::MoveOrderToAwaitingDeposit,
            PermissionName::MoveOrderToDepositPaid,
            PermissionName::ConfirmDepositReceipt,
        ]);
        $order = $this->awaitingDeposit($headers);
        $this->move($headers, $order, OrderStatus::DepositPaid)->assertOk();

        // Act
        $response = $this->confirm($headers, $order->refresh(), true);

        // Assert
        $response->assertStatus(422)->assertJsonPath(
            'message',
            'لا يمكن لمن نقل الطلبية إلى «عربون مدفوع» أن يؤكّد استلام العربون — يلزم شخص آخر',
        );

        $order->refresh();
        $this->assertFalse($order->is_deposit_received);
        $this->assertNull($order->deposit_confirmed_at);
    }

    public function test_the_screen_is_told_who_may_tick_before_anybody_taps(): void
    {
        // Arrange
        [, $clerk] = $this->userWith([
            PermissionName::ViewOrders,
            PermissionName::MoveOrderToAwaitingDeposit,
            PermissionName::MoveOrderToDepositPaid,
            PermissionName::ConfirmDepositReceipt,
        ]);
        [, $books] = $this->accountant();
        $order = $this->awaitingDeposit($clerk);
        $this->move($clerk, $order, OrderStatus::DepositPaid)->assertOk();

        // Act
        $toClaimer = $this->withHeaders($clerk)->getJson("/api/v1/orders/{$order->id}");
        $toColleague = $this->withHeaders($books)->getJson("/api/v1/orders/{$order->id}");

        // Assert — the box is greyed for the person who made the claim rather than refusing them
        // after the tap, and the endpoint enforces the same answer.
        $toClaimer->assertOk()->assertJsonPath('data.can_confirm_deposit', false);
        $toColleague->assertOk()->assertJsonPath('data.can_confirm_deposit', true);
    }

    public function test_the_claimer_may_still_take_a_confirmation_back(): void
    {
        // Arrange
        [, $clerk] = $this->userWith([
            PermissionName::ViewOrders,
            PermissionName::MoveOrderToAwaitingDeposit,
            PermissionName::MoveOrderToDepositPaid,
            PermissionName::ConfirmDepositReceipt,
        ]);
        [, $books] = $this->accountant();
        $order = $this->awaitingDeposit($clerk);
        $this->move($clerk, $order, OrderStatus::DepositPaid)->assertOk();
        $this->confirm($books, $order->refresh(), true)->assertOk();

        // Act
        $response = $this->confirm($clerk, $order->refresh(), false);

        // Assert — un-ticking withdraws a statement rather than making one, so the rule does not
        // apply to it: a confirmation nobody may take back is worse than one anybody may.
        $response->assertOk()
            ->assertJsonPath('message', 'أُلغي تأكيد استلام العربون')
            ->assertJsonPath('data.is_deposit_received', false);

        $order->refresh();
        $this->assertNull($order->deposit_confirmed_at);
        $this->assertNull($order->deposit_confirmed_by);
    }

    public function test_a_move_with_no_actor_bars_nobody(): void
    {
        // Arrange — a console command or an import moves an order and stamps no name.
        [$books, $headers] = $this->accountant();
        $order = Order::factory()->status(OrderStatus::DepositPaid)->create([
            'deposit_expected_amount' => '250.00',
            'deposit_paid_at' => now(),
            'deposit_claimed_by' => null,
        ]);

        // Act
        $response = $this->confirm($headers, $order, true);

        // Assert — there is nobody to be the second person *to*, so the tick is allowed.
        $response->assertOk()->assertJsonPath('data.is_deposit_received', true);
        $this->assertSame((int) $books->id, (int) $order->refresh()->deposit_confirmed_by);
    }

    public function test_re_claiming_replaces_who_is_barred(): void
    {
        // Arrange — A claims, it is walked back, B claims.
        [, $first] = $this->userWith([
            PermissionName::ViewOrders,
            PermissionName::MoveOrderToAwaitingDeposit,
            PermissionName::MoveOrderToDepositPaid,
            PermissionName::ConfirmDepositReceipt,
        ]);
        [, $second] = $this->userWith([
            PermissionName::ViewOrders,
            PermissionName::MoveOrderToAwaitingDeposit,
            PermissionName::MoveOrderToDepositPaid,
            PermissionName::ConfirmDepositReceipt,
        ]);
        $order = $this->awaitingDeposit($first);
        $this->move($first, $order, OrderStatus::DepositPaid)->assertOk();
        $this->move($first, $order->refresh(), OrderStatus::AwaitingDeposit, [
            'deposit_amount' => '250',
            'deposit_method' => PaymentMethod::Cash->value,
        ])->assertOk();
        $this->move($second, $order->refresh(), OrderStatus::DepositPaid)->assertOk();

        // Act
        $byFirst = $this->confirm($first, $order->refresh(), true);
        $bySecond = $this->confirm($second, $order->refresh(), true);

        // Assert — the rule guards the *standing* claim, not every claim ever made.
        $byFirst->assertOk()->assertJsonPath('data.is_deposit_received', true);
        $bySecond->assertStatus(422);
    }

    public function test_an_order_that_never_asked_for_a_deposit_has_nothing_to_confirm(): void
    {
        // Arrange
        [, $headers] = $this->accountant();
        $order = $this->newOrder();

        // Act
        $response = $this->confirm($headers, $order, true);

        // Assert
        $response->assertStatus(422)
            ->assertJsonPath('message', 'لا يوجد عربون على هذه الطلبية لتأكيد استلامه');
        $this->assertFalse($order->refresh()->is_deposit_received);
    }

    public function test_the_tick_costs_its_own_grant(): void
    {
        // Arrange
        [, $clerk] = $this->clerk();
        [, $viewer] = $this->userWith([PermissionName::ViewOrders]);
        $order = $this->awaitingDeposit($clerk);
        $this->move($clerk, $order, OrderStatus::DepositPaid)->assertOk();

        // Act
        $forbidden = $this->confirm($viewer, $order->refresh(), true);

        // `withHeaders()` sets defaults on the test instance rather than on one request, so
        // without this the "unauthenticated" call below would still be carrying the viewer's
        // token — and would assert 403 while claiming to prove 401.
        $this->flushHeaders();

        $unauthenticated = $this->patchJson(
            "/api/v1/orders/{$order->id}/deposit-receipt",
            ['received' => true],
        );

        // Assert — holding the till or the status grants is not holding this one.
        $forbidden->assertStatus(403);
        $unauthenticated->assertStatus(401);
        $this->assertFalse($order->refresh()->is_deposit_received);
    }

    public function test_an_ordinary_edit_cannot_set_the_flag(): void
    {
        // Arrange — the reason this is an endpoint of its own rather than a column on the order.
        [, $clerk] = $this->clerk();
        [, $manager] = $this->userWith([PermissionName::ViewOrders, PermissionName::ManageOrders]);
        $order = $this->awaitingDeposit($clerk);

        // Act
        $response = $this->withHeaders($manager)->putJson("/api/v1/orders/{$order->id}", [
            'is_deposit_received' => true,
            'deposit_expected_amount' => '9999.00',
        ]);

        // Assert — whatever the endpoint makes of the rest of the payload, the accountant's
        // signature is not something an order edit can forge.
        $order->refresh();
        $this->assertFalse($order->is_deposit_received);
        $this->assertSame('250.00', (string) $order->deposit_expected_amount);
        $this->assertNotNull($response);
    }

    // ── what the flag does not do ────────────────────────────────────────────────────────────

    public function test_recording_or_reversing_a_payment_never_moves_the_flag(): void
    {
        // Arrange
        [, $clerk] = $this->clerk();
        [, $books] = $this->accountant();
        $order = $this->awaitingDeposit($clerk);
        $this->move($clerk, $order, OrderStatus::DepositPaid)->assertOk();

        // Act — the money is posted, then taken back, with nobody ticking anything.
        $this->withHeaders($books)->postJson("/api/v1/orders/{$order->id}/payments", [
            'amount' => '250',
            'method' => PaymentMethod::Cash->value,
        ])->assertCreated();

        $paidState = $order->refresh()->is_deposit_received;

        $payment = OrderPayment::firstOrFail();
        $this->withHeaders($books)->postJson(
            "/api/v1/orders/{$order->id}/payments/{$payment->id}/reverse",
            ['reason' => 'قيد خاطئ'],
        )->assertSuccessful();

        // Assert — **the flag is an attestation, not a derivation.** A ledger entry is not a
        // person saying they checked, and this is the guard against anybody wiring the two
        // together later.
        $this->assertFalse($paidState);
        $this->assertFalse($order->refresh()->is_deposit_received);
    }

    public function test_an_unconfirmed_deposit_stops_nothing(): void
    {
        // Arrange — an order claimed but never confirmed, and a user who can walk the whole road.
        [, $headers] = $this->userWith([
            PermissionName::ViewOrders,
            PermissionName::MoveOrderToAwaitingDeposit,
            PermissionName::MoveOrderToDepositPaid,
            PermissionName::MoveOrderToReadyToPrint,
            PermissionName::MoveOrderToPrinting,
            PermissionName::MoveOrderToReady,
            PermissionName::DispatchOrders,
            PermissionName::MarkOrdersDelivered,
            PermissionName::SettleOrders,
            PermissionName::RecordOrderPayments,
        ]);
        $order = $this->awaitingDeposit($headers);

        // The customer is collecting it themselves, so «جاهزة» leads to «استلام مكتب» and the
        // road needs no carrier — the destination decides which dispatch status "it is leaving"
        // means, and this test is about the deposit rather than about delivery.
        $order->forceFill(['fulfilment_type' => FulfilmentType::OfficePickup])->save();

        // Act — every step of the road, in order, with the flag false throughout.
        $road = [
            OrderStatus::DepositPaid,
            OrderStatus::ReadyToPrint,
            OrderStatus::Printing,
            OrderStatus::Ready,
        ];

        foreach ($road as $step) {
            $this->move($headers, $order->refresh(), $step)
                ->assertOk()
                ->assertJsonPath('data.is_deposit_received', false);
        }

        $dispatch = $this->move($headers, $order->refresh(), OrderStatus::OfficePickup);
        $delivered = $this->move($headers, $order->refresh(), OrderStatus::Delivered, [
            'payment_amount' => '1000',
            'payment_method' => PaymentMethod::Cash->value,
        ]);
        $settled = $this->move($headers, $order->refresh(), OrderStatus::Settled);

        // Assert — **bookkeeping must not stop a press.** Nothing on the road consulted the flag,
        // and the only refusal money can make here is the pre-existing settlement rule, which
        // reads the ledger rather than this.
        $dispatch->assertOk();
        $delivered->assertOk();
        $settled->assertOk()->assertJsonPath('data.status', OrderStatus::Settled->value);

        $order->refresh();
        $this->assertFalse($order->is_deposit_received);
        $this->assertTrue($order->awaitsDepositConfirmation());
    }
}
