<?php

declare(strict_types=1);

namespace App\Domain\Support\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Models\Order;
use App\Domain\Support\Enums\TicketStatus;
use Database\Factories\SupportTicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One conversation between a customer and the shop.
 *
 * Its own context rather than a use of `Comment` — see the migration for the two reasons, the
 * second of which is that a customer's comments are staff notes *about* them and must never
 * become readable from the app.
 *
 * **`customer_id` is not fillable**, like `Order`'s: a ticket never changes hands, and leaving
 * it unfillable is what stops an update moving one between customers. Neither is `status`, which
 * is moved by actions that know what a move means.
 */
#[UseFactory(SupportTicketFactory::class)]
#[Fillable(['subject', 'order_id', 'assigned_to'])]
class SupportTicket extends Model
{
    /** @use HasFactory<SupportTicketFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'customer_read_at' => 'datetime',
            'staff_read_at' => 'datetime',
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The order this is about, when it is about one.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * The thread, oldest first — a conversation is read as one.
     *
     * @return HasMany<TicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
    }

    /**
     * The most recent message alone, for the list.
     *
     * **A relation of its own rather than `messages` with a limit on it.** «الدعم» draws one
     * line of the latest message under each subject; eager-loading `messages` to get it would
     * put a whole thread on every row of the list *and* leave the list's `messages` key
     * half-populated — one message where the thread endpoint sends all of them, which is the
     * kind of difference that produces a screen showing a conversation with one line in it.
     *
     * @return HasOne<TicketMessage, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(TicketMessage::class)->latestOfMany();
    }

    /**
     * How many messages this side has not seen.
     *
     * **Derived from the cursor rather than kept as a counter**, which is the whole reason the
     * schema stores a timestamp. A counter has to be incremented by every writer and decremented
     * by every reader, and is wrong forever the first time either is missed; a cursor is written
     * once, by the side that read, and this answer is recomputed from it every time.
     *
     * Messages I wrote myself never count: I have read them by definition.
     */
    public function unreadFor(bool $staff): int
    {
        $cursor = $staff ? $this->staff_read_at : $this->customer_read_at;

        return $this->messages()
            // The other side's messages: staff count the customer's, the customer counts staff's.
            ->whereNotNull($staff ? 'customer_id' : 'user_id')
            ->when($cursor instanceof Carbon, fn ($q) => $q->where('created_at', '>', $cursor))
            ->count();
    }
}
