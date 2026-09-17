<?php

declare(strict_types=1);

namespace App\Domain\Support\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use Database\Factories\TicketMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One thing somebody said in a ticket.
 *
 * **Only `body` is fillable, and that is the model's one rule** — the same rule `Comment` keeps
 * and for the same reason. The author is stamped by the action that writes the row, from the
 * authenticated party, and has no path to change afterwards; a mass assignment from a request
 * body can never sign somebody else's name to a sentence.
 *
 * Exactly one of `user_id` and `customer_id` is set, enforced by a `CHECK` on the table rather
 * than only here — an unsigned message is a sentence nobody can be asked about.
 */
#[UseFactory(TicketMessageFactory::class)]
#[Fillable(['body'])]
class TicketMessage extends Model
{
    /** @use HasFactory<TicketMessageFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<SupportTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /**
     * The member of staff who wrote it, when staff did.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Whether the customer wrote this, rather than the shop. */
    public function isFromCustomer(): bool
    {
        return $this->customer_id !== null;
    }
}
