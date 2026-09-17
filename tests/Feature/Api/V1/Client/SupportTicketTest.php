<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Client;

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Models\Order;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Models\TicketMessage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The support desk — one thread per question, two sides reading it.
 *
 * **Three things are being held here.** That a customer reaches only their own threads, with no
 * customer id in any path. That the status follows from who spoke rather than from a field —
 * staff replying puts a ticket on a desk, a customer replying to a closed one reopens it. And
 * that unread is derived from a read cursor rather than kept as a counter, which is the whole
 * reason the schema stores a timestamp.
 *
 * Arrange - Act - Assert throughout.
 */
class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    private function customer(string $phone = '0911111111'): Customer
    {
        return Customer::factory()->registered()->create(['phone' => $phone]);
    }

    /**
     * @return array<string, string>
     */
    private function bearerFor(Customer $customer): array
    {
        return ['Authorization' => 'Bearer '.$customer->createToken('app')->plainTextToken];
    }

    /**
     * @return array<string, string>
     */
    private function staff(PermissionName ...$permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (PermissionName $p) => $p->value, $permissions));

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    // ─────────────────────────── opening one ───────────────────────────

    public function test_a_customer_opens_a_ticket_with_its_first_message(): void
    {
        // Arrange
        $me = $this->customer();

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->postJson('/api/v1/client/support/tickets', [
            'subject' => 'تأخر في التوصيل',
            'body' => 'الطلبية خرجت من ثلاثة أيام ولم تصل',
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.subject', 'تأخر في التوصيل')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.from', 'me');

        $this->assertDatabaseHas('support_tickets', ['customer_id' => $me->id, 'status' => 'open']);
    }

    /**
     * A ticket with no message is a subject line nobody can answer — it would sit in the queue
     * looking like work and carrying no question. So the two are one transaction.
     */
    public function test_a_ticket_and_its_first_message_are_written_together(): void
    {
        // Arrange
        $me = $this->customer();

        // Act
        $this->withHeaders($this->bearerFor($me))->postJson('/api/v1/client/support/tickets', [
            'subject' => 'سؤال',
            'body' => 'متى يفتح المحل؟',
        ])->assertCreated();

        // Assert
        $this->assertSame(1, SupportTicket::query()->count());
        $this->assertSame(1, TicketMessage::query()->count());
    }

    public function test_a_ticket_needs_a_subject_and_a_body(): void
    {
        // Act
        $response = $this->withHeaders($this->bearerFor($this->customer()))
            ->postJson('/api/v1/client/support/tickets', []);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors(['subject', 'body']);
    }

    public function test_a_ticket_can_be_about_one_of_my_own_orders(): void
    {
        // Arrange
        $me = $this->customer();
        $order = Order::factory()->create(['customer_id' => $me->id]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->postJson('/api/v1/client/support/tickets', [
            'subject' => 'بخصوص طلبيتي',
            'body' => 'أريد تعديل العنوان',
            'order_id' => $order->id,
        ]);

        // Assert
        $response->assertCreated()->assertJsonPath('data.order.code', $order->code);
    }

    /**
     * **Resolved through the customer's own orders, not by an `exists` rule.** A validation rule
     * could only have said the order exists — which is true, and belongs to somebody else.
     */
    public function test_a_ticket_cannot_be_attached_to_another_customers_order(): void
    {
        // Arrange
        $me = $this->customer();
        $theirs = Order::factory()->create(['customer_id' => $this->customer('0922222222')->id]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->postJson('/api/v1/client/support/tickets', [
            'subject' => 'فضول',
            'body' => 'ما حال هذه الطلبية؟',
            'order_id' => $theirs->id,
        ]);

        // Assert
        $response->assertNotFound();
        $this->assertSame(0, SupportTicket::query()->count());
    }

    // ─────────────────────────── reading them ───────────────────────────

    public function test_the_list_shows_only_my_own_threads(): void
    {
        // Arrange
        $me = $this->customer();
        $mine = SupportTicket::factory()->create(['customer_id' => $me->id]);
        $theirs = SupportTicket::factory()->create(['customer_id' => $this->customer('0922222222')->id]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->getJson('/api/v1/client/support/tickets');

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->assertNotContains($theirs->id, array_column((array) $response->json('data'), 'id'));
    }

    public function test_another_customers_thread_is_a_404(): void
    {
        // Arrange
        $me = $this->customer();
        $theirs = SupportTicket::factory()->create(['customer_id' => $this->customer('0922222222')->id]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))
            ->getJson("/api/v1/client/support/tickets/{$theirs->id}");

        // Assert
        $response->assertNotFound();
    }

    public function test_the_list_can_be_narrowed_to_the_live_threads(): void
    {
        // Arrange
        $me = $this->customer();
        $open = SupportTicket::factory()->create(['customer_id' => $me->id]);
        SupportTicket::factory()->closed()->create(['customer_id' => $me->id]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))
            ->getJson('/api/v1/client/support/tickets?open=1');

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open->id);
    }

    /**
     * Which colleague answered is the shop's internal arrangement, and naming them makes an
     * individual the target of a complaint about a decision the business made.
     */
    public function test_the_customer_is_never_told_which_member_of_staff_replied(): void
    {
        // Arrange
        $me = $this->customer();
        $ticket = SupportTicket::factory()->create(['customer_id' => $me->id]);
        $agent = User::factory()->create(['name' => 'محمد الموظف']);
        TicketMessage::factory()->fromStaff($agent->id)->create(['support_ticket_id' => $ticket->id]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))
            ->getJson("/api/v1/client/support/tickets/{$ticket->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.messages.0.from', 'support')
            ->assertDontSee('محمد الموظف')
            ->assertDontSee('assigned_to')
            ->assertDontSee('staff_read_at');
    }

    // ─────────────────────────── unread ───────────────────────────

    /**
     * **Derived from the cursor, never stored.** A counter has to be incremented by every writer
     * and decremented by every reader, and is wrong forever the first time either is missed.
     */
    public function test_unread_counts_the_replies_since_i_last_looked(): void
    {
        // Arrange
        $me = $this->customer();
        $ticket = SupportTicket::factory()->create([
            'customer_id' => $me->id,
            'customer_read_at' => now()->subHour(),
        ]);
        $agent = User::factory()->create();
        TicketMessage::factory()->count(2)->fromStaff($agent->id)->create([
            'support_ticket_id' => $ticket->id,
        ]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->getJson('/api/v1/client/support/tickets');

        // Assert
        $response->assertOk()->assertJsonPath('data.0.unread_count', 2);
    }

    /**
     * Reading a conversation is what marks it read — which is why there is no «mark as read»
     * endpoint to forget to call.
     */
    public function test_opening_a_thread_clears_its_unread_count(): void
    {
        // Arrange
        $me = $this->customer();
        $ticket = SupportTicket::factory()->create([
            'customer_id' => $me->id,
            'customer_read_at' => now()->subHour(),
        ]);
        TicketMessage::factory()->fromStaff(User::factory()->create()->id)
            ->create(['support_ticket_id' => $ticket->id]);

        // Act
        $this->withHeaders($this->bearerFor($me))
            ->getJson("/api/v1/client/support/tickets/{$ticket->id}")->assertOk();

        $list = $this->withHeaders($this->bearerFor($me))->getJson('/api/v1/client/support/tickets');

        // Assert
        $list->assertOk()->assertJsonPath('data.0.unread_count', 0);
    }

    /** My own messages are read by definition. */
    public function test_my_own_messages_never_count_as_unread(): void
    {
        // Arrange
        $me = $this->customer();

        // Act
        $this->withHeaders($this->bearerFor($me))->postJson('/api/v1/client/support/tickets', [
            'subject' => 'سؤال',
            'body' => 'متى تفتحون؟',
        ])->assertCreated();

        $list = $this->withHeaders($this->bearerFor($me))->getJson('/api/v1/client/support/tickets');

        // Assert
        $list->assertOk()->assertJsonPath('data.0.unread_count', 0);
    }

    // ─────────────────── the status follows who spoke ───────────────────

    public function test_a_staff_reply_puts_the_ticket_on_a_desk(): void
    {
        // Arrange
        $ticket = SupportTicket::factory()->create(['customer_id' => $this->customer()->id]);
        $headers = $this->staff(PermissionName::ViewSupportTickets, PermissionName::ManageSupportTickets);

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/support/tickets/{$ticket->id}/messages",
            ['body' => 'نعتذر عن التأخير، الطلبية في الطريق'],
        );

        // Assert
        $response->assertCreated();
        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticket->id,
            'status' => TicketStatus::InProgress->value,
        ]);
    }

    /**
     * **A closed ticket somebody is still writing into is not closed.** It is closed on paper
     * and open in fact, and that gap is where a customer gets ignored.
     */
    public function test_a_customer_reply_reopens_a_closed_ticket(): void
    {
        // Arrange
        $me = $this->customer();
        $ticket = SupportTicket::factory()->closed()->create(['customer_id' => $me->id]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->postJson(
            "/api/v1/client/support/tickets/{$ticket->id}/messages",
            ['body' => 'المشكلة لم تُحل'],
        );

        // Assert
        $response->assertCreated()->assertJsonPath('data.status', 'open');
        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticket->id,
            'status' => TicketStatus::Open->value,
            'closed_at' => null,
        ]);
    }

    /**
     * The other half of that asymmetry: staff adding to a conversation the shop decided was over
     * is a decision worth making deliberately.
     */
    public function test_staff_cannot_write_into_a_closed_ticket(): void
    {
        // Arrange
        $ticket = SupportTicket::factory()->closed()->create(['customer_id' => $this->customer()->id]);
        $headers = $this->staff(PermissionName::ViewSupportTickets, PermissionName::ManageSupportTickets);

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/support/tickets/{$ticket->id}/messages",
            ['body' => 'شيء أخير'],
        );

        // Assert
        $response->assertStatus(422)->assertJsonPath('status', false);
        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticket->id,
            'status' => TicketStatus::Closed->value,
        ]);
    }

    // ─────────────────────────── the desk ───────────────────────────

    public function test_the_queue_needs_the_view_permission(): void
    {
        // Act
        $response = $this->withHeaders($this->staff())->getJson('/api/v1/support/tickets');

        // Assert
        $response->assertForbidden();
    }

    public function test_replying_needs_more_than_reading(): void
    {
        // Arrange
        $ticket = SupportTicket::factory()->create(['customer_id' => $this->customer()->id]);

        // Act — holds `support.view` and nothing else.
        $response = $this->withHeaders($this->staff(PermissionName::ViewSupportTickets))->postJson(
            "/api/v1/support/tickets/{$ticket->id}/messages",
            ['body' => 'رد'],
        );

        // Assert
        $response->assertForbidden();
    }

    public function test_the_desk_sees_every_customers_threads(): void
    {
        // Arrange
        SupportTicket::factory()->create(['customer_id' => $this->customer()->id]);
        SupportTicket::factory()->create(['customer_id' => $this->customer('0922222222')->id]);

        // Act
        $response = $this->withHeaders($this->staff(PermissionName::ViewSupportTickets))
            ->getJson('/api/v1/support/tickets');

        // Assert
        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_ticket_can_be_assigned_and_unassigned(): void
    {
        // Arrange
        $ticket = SupportTicket::factory()->create(['customer_id' => $this->customer()->id]);
        $agent = User::factory()->create();
        $headers = $this->staff(PermissionName::ViewSupportTickets, PermissionName::ManageSupportTickets);

        // Act
        $assigned = $this->withHeaders($headers)->patchJson(
            "/api/v1/support/tickets/{$ticket->id}/assignment",
            ['assigned_to' => $agent->id],
        );
        $cleared = $this->withHeaders($headers)->patchJson(
            "/api/v1/support/tickets/{$ticket->id}/assignment",
            ['assigned_to' => null],
        );

        // Assert
        $assigned->assertOk()->assertJsonPath('data.assigned_to', $agent->id);
        $cleared->assertOk()->assertJsonPath('data.assigned_to', null);
    }

    /**
     * Two people pressing the same button is not a failure, and the second must not overwrite
     * the first one's name.
     */
    public function test_closing_a_closed_ticket_is_not_an_error(): void
    {
        // Arrange
        $ticket = SupportTicket::factory()->create(['customer_id' => $this->customer()->id]);
        $first = $this->staff(PermissionName::ViewSupportTickets, PermissionName::ManageSupportTickets);
        $second = $this->staff(PermissionName::ViewSupportTickets, PermissionName::ManageSupportTickets);

        // Act
        $this->withHeaders($first)->postJson("/api/v1/support/tickets/{$ticket->id}/close")->assertOk();
        $closedBy = SupportTicket::query()->findOrFail($ticket->id)->closed_by;
        $again = $this->withHeaders($second)->postJson("/api/v1/support/tickets/{$ticket->id}/close");

        // Assert
        $again->assertOk();
        $this->assertSame($closedBy, SupportTicket::query()->findOrFail($ticket->id)->closed_by);
    }

    // ─────────────────────────── the wall ───────────────────────────

    public function test_the_customer_support_api_needs_a_customer_token(): void
    {
        // Act
        $response = $this->getJson('/api/v1/client/support/tickets');

        // Assert
        $response->assertUnauthorized();
    }

    public function test_a_customer_token_cannot_reach_the_desk(): void
    {
        // Act
        $response = $this->withHeaders($this->bearerFor($this->customer()))
            ->getJson('/api/v1/support/tickets');

        // Assert
        $response->assertUnauthorized();
    }

    /**
     * The table refuses an unsigned message — a sentence nobody can be asked about.
     */
    public function test_the_database_refuses_a_message_with_no_author(): void
    {
        // Arrange
        $ticket = SupportTicket::factory()->create(['customer_id' => $this->customer()->id]);

        // Assert
        $this->expectException(QueryException::class);

        // Act
        DB::table('ticket_messages')->insert([
            'support_ticket_id' => $ticket->id,
            'body' => 'من كتب هذه؟',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
