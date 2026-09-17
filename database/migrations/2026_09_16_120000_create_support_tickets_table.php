<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a customer reaches a person.
 *
 * **Its own pair of tables rather than `comments`, and that is the decision worth recording.**
 * `Comment` is already polymorphic and already generalised out of the Customer context — see
 * GENERAL-COMMENTS.md — so it looks like the obvious home. It is not, for two reasons.
 *
 * `Comment::author()` is `belongsTo(User::class)`: staff only, and a ticket's author is a
 * customer. And more seriously, a customer's comments are *the notes staff write to each other
 * about them* — routes/api.php calls them «what staff write to each other about them», written
 * in the belief that the customer will never read them. Any change that made `Comment` readable
 * from the customer app would risk every one of those. A ticket also carries a status, an
 * assignment and a read cursor per side, none of which a note has.
 *
 * **A message names its author with two nullable columns, not a morph.** `user_id` for staff,
 * `customer_id` for the customer, exactly one of them set — the `CHECK` below says so, the same
 * way `billboards` says a banner has one destination. Two real foreign keys keep referential
 * integrity that a `morph_type` string cannot, and «who wrote this» is a closed question with
 * exactly two answers; it is not going to grow a third.
 *
 * **Unread is two timestamps, not two counters.** A counter has to be incremented by whoever
 * writes, decremented by whoever reads, and is wrong forever the first time either is missed. A
 * cursor is written once by the side that read, and the count is derived — `messages after my
 * cursor, not written by me` — so it cannot drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            // What the customer is writing about. Free text: a closed list of topics would be a
            // list we guessed, and the first ticket outside it would be filed wrongly to get
            // past the form.
            $table->string('subject', 200);

            // A `TicketStatus` value.
            $table->string('status', 20)->default('open');

            // **The order this is about, when it is about one.** «تأخر في طلبية #١٢٢٠» is the
            // commonest ticket there will ever be, and having it as a column rather than a
            // sentence means the staff screen can open the order from the thread. `nullOnDelete`
            // because an archived order should cost the ticket its link, not its existence.
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();

            // Whose desk it is on. Nullable: an unassigned ticket is the ordinary state of a new
            // one, and «معلّقة على أحد» is a question the list answers by this being null.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            // The read cursors. Null means «never opened it», which is the correct starting
            // state for both sides — a ticket nobody has read yet has everything unread.
            $table->timestamp('customer_read_at')->nullable();
            $table->timestamp('staff_read_at')->nullable();

            // Denormalised so the list can sort by activity without joining every thread. Written
            // by the same action that writes a message, inside its transaction.
            $table->timestamp('last_message_at')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes()->index();

            // What both lists order by.
            $table->index(['customer_id', 'last_message_at']);
            $table->index(['status', 'last_message_at']);
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();

            // Exactly one of these, enforced below. See the class comment for why this is not a
            // morph.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->text('body');

            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['support_ticket_id', 'id']);
        });

        // A message has one author. Stated in the database because it is an invariant of the
        // row: a seeder, an import or a future endpoint cannot write an unsigned message either,
        // and an unsigned one is a sentence nobody can be asked about.
        DB::statement(<<<'SQL'
            ALTER TABLE ticket_messages
            ADD CONSTRAINT ticket_messages_one_author
            CHECK (
                (user_id IS NOT NULL AND customer_id IS NULL)
                OR (user_id IS NULL AND customer_id IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
