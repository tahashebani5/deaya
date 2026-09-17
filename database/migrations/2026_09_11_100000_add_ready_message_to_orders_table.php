<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «هل أُبلِغ الزبون أنّ طلبه جاهز؟» — the one fact about an order that happens outside this system.
 *
 * The message goes out on WhatsApp or by telephone, so nothing here can observe it. What is
 * stored is an employee saying they sent it, which is why this is two columns and not a status:
 * it describes the *person*, not the bags, and it crosses every status after «جاهزة» rather than
 * standing between two of them. See Docs/orders/ORDER-READY-MESSAGE.md §١.
 *
 * **A timestamp, not a boolean**, exactly as `stock_deducted_at` and
 * `carrier_collection_recorded_at` beside it: «هل؟» and «متى؟» are both asked of a message a
 * customer says never arrived, and one column answers both at no extra cost. Null is «لم تُرسل»,
 * and there is no third state to lose.
 *
 * **And «مَن» is a column rather than a read of the audit trail.** The whole purpose of the mark
 * is confirming that the employee responsible did the work, so their name is the answer itself —
 * not a footnote to be found by walking `activity_log` once per order opened. The trail still
 * records both columns through `Auditable`; it answers a different question, «من غيّرها بعدها؟».
 *
 * Nullable with no backfill, and that is deliberate. Every order already in the table went out
 * before anybody was asked to record this, and marking them «أُرسلت» would write a claim nobody
 * made into the history. They stay null, and §٥ of the design keeps them out of the queue by
 * excluding orders the customer already has — which is what stops the box opening on the whole
 * archive the day this ships.
 *
 * The index is **partial**, over exactly the queue's own predicate: the pending set is a small
 * slice of a growing table, and `ready_at` is what the list is ordered by once inside it. Live
 * rows only, like every other index on this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Beside `ready_at`, because it is the stamp this one is read against: the message is
            // due from the moment that column is written and never before it.
            $table->timestamp('ready_message_sent_at')->nullable()->after('ready_at');

            // `nullOnDelete` rather than a cascade: an employee leaving must not take the record
            // of the work with them — the order keeps saying a message went out, and only the
            // name behind it is gone.
            $table->foreignId('ready_message_sent_by')
                ->nullable()
                ->after('ready_message_sent_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        DB::statement(
            'CREATE INDEX orders_ready_message_pending_index ON orders (ready_at) '
            .'WHERE ready_message_sent_at IS NULL AND ready_at IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_ready_message_pending_index');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ready_message_sent_by');
            $table->dropColumn('ready_message_sent_at');
        });
    }
};
