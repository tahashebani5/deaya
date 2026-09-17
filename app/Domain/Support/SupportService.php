<?php

declare(strict_types=1);

namespace App\Domain\Support;

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Support\Actions\CloseTicket;
use App\Domain\Support\Actions\MarkTicketRead;
use App\Domain\Support\Actions\OpenTicket;
use App\Domain\Support\Actions\PostTicketMessage;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Models\TicketMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The Support module's front door.
 *
 * **Two read paths, and they are not one method with a flag** — the same split `MarketingService`
 * draws. `paginateForCustomer()` confines to one customer's own threads; `paginateForStaff()`
 * confines to nothing and filters on status and assignee instead. A boolean choosing between
 * them is the argument somebody eventually passes the wrong way round, and the wrong way round
 * here is one customer reading another's conversation.
 *
 * The same reasoning governs {@see self::findForCustomer()}: scoped in the query, never checked
 * after the fact.
 */
class SupportService
{
    public function __construct(
        private readonly OpenTicket $openTicket,
        private readonly PostTicketMessage $postMessage,
        private readonly CloseTicket $closeTicket,
        private readonly MarkTicketRead $markRead,
    ) {}

    // ─────────────────────────── the customer's side ───────────────────────────

    /**
     * How many replies from the shop this customer has not read, across every thread they own.
     *
     * **One query, not a sum over {@see SupportTicket::unreadFor()}.** That method answers for
     * one ticket and would be N+1 here — a customer with twelve threads would cost twelve
     * counts to draw one number on a tile. The rule it encodes is copied rather than the method
     * reused, and the two are pinned together by `CustomerBadgeTest`.
     *
     * Derived from the read cursor for the same reason the per-ticket number is: a stored
     * counter drifts the first time a code path forgets to decrement it, and a badge that lies
     * is worse than no badge.
     *
     * Closed threads count. A shop's last word on a question is still a word the customer has
     * not read, and hiding it because the thread is closed is how an answer goes unseen.
     */
    public function countUnreadForCustomer(int $customerId): int
    {
        return TicketMessage::query()
            ->whereHas(
                'ticket',
                fn ($q) => $q->where('customer_id', $customerId),
            )
            // The shop's messages, never the customer's own.
            ->whereNotNull('user_id')
            ->whereRaw(
                'ticket_messages.created_at > COALESCE('
                .'(select customer_read_at from support_tickets where support_tickets.id = ticket_messages.support_ticket_id),'
                ." '-infinity')"
            )
            ->count();
    }

    /**
     * One customer's own threads, most recently active first.
     *
     * @return LengthAwarePaginator<int, SupportTicket>
     */
    public function paginateForCustomer(int $customerId, ?bool $openOnly = null, int $perPage = 15): LengthAwarePaginator
    {
        return SupportTicket::query()
            ->where('customer_id', $customerId)
            ->when($openOnly === true, fn ($q) => $q->where('status', '!=', TicketStatus::Closed))
            ->when($openOnly === false, fn ($q) => $q->where('status', TicketStatus::Closed))
            ->with('order')
            // **Counted, and only the newest one loaded.** The list draws a preview line and
            // «٣ رسائل»; eager-loading `messages` to get either would pull a customer's entire
            // correspondence down to render two lines of it — and would leave the list's
            // `messages` key looking like a thread with one message in it.
            ->withCount('messages')
            ->with('latestMessage')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * One of a given customer's threads, or a 404.
     *
     * **Scoped in the query, never checked afterwards.** `SupportTicket::find()` followed by an
     * ownership test is the shape that fails the day somebody forgets the test; a `where` on the
     * customer means a foreign id never becomes an object at all.
     */
    public function findForCustomer(int $customerId, int $ticketId): SupportTicket
    {
        return SupportTicket::query()
            ->where('customer_id', $customerId)
            ->with(['messages', 'order'])
            ->findOrFail($ticketId);
    }

    public function open(Customer $customer, string $subject, string $body, ?int $orderId = null): SupportTicket
    {
        return ($this->openTicket)($customer, $subject, $body, $orderId);
    }

    public function replyAsCustomer(SupportTicket $ticket, Customer $customer, string $body): TicketMessage
    {
        return ($this->postMessage)($ticket, $body, customer: $customer);
    }

    // ─────────────────────────── the shop's side ───────────────────────────

    /**
     * Every thread, filtered the way a queue is read.
     *
     * @return LengthAwarePaginator<int, SupportTicket>
     */
    public function paginateForStaff(
        ?TicketStatus $status = null,
        ?int $assignedTo = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return SupportTicket::query()
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($assignedTo !== null, fn ($q) => $q->where('assigned_to', $assignedTo))
            ->with(['customer', 'order', 'assignee'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(int $ticketId): SupportTicket
    {
        return SupportTicket::query()->with(['messages', 'customer', 'order', 'assignee'])->findOrFail($ticketId);
    }

    public function replyAsStaff(SupportTicket $ticket, User $staff, string $body): TicketMessage
    {
        return ($this->postMessage)($ticket, $body, staff: $staff);
    }

    public function assign(SupportTicket $ticket, ?int $userId): SupportTicket
    {
        $ticket->update(['assigned_to' => $userId]);

        return $ticket->refresh();
    }

    public function close(SupportTicket $ticket, ?User $staff = null): SupportTicket
    {
        return ($this->closeTicket)($ticket, $staff);
    }

    /** Reading a thread is what marks it read — there is no separate button to forget. */
    public function markRead(SupportTicket $ticket, bool $staff): void
    {
        ($this->markRead)($ticket, $staff);
    }
}
