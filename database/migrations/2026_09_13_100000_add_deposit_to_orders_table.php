<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * العربون — what was asked for, what was claimed, and who checked that it arrived.
 *
 * **Three separate facts, and the whole design is in keeping them apart.** «انتظار العربون» records
 * an *expectation*: a figure the shop names and a method it expects it by, neither of which is
 * money that has moved. «عربون مدفوع» records a *claim*: an employee saying the customer paid.
 * `is_deposit_received` records a *confirmation*: a second employee saying they looked and the
 * money is there. Folding any two of those into one column would lose which of them a row meant —
 * and the gap between the second and the third is exactly what the accountant was asking to see.
 *
 * **`deposit_expected_amount`, never `deposit_amount`.** The word matters: this figure is
 * «قيمة تقديرية», and a column called `deposit_amount` beside `paid_amount` invites every future
 * screen to read it as money received. That is the mistake `collected_amount` made — see
 * Docs/payments/PAYMENT-AT-STATUS-CHANGE.md §٢ — and the name is what stops it being made twice.
 * The real deposit is an ordinary `order_payments` row like every other payment.
 *
 * **`deposit_claimed_by` and `deposit_confirmed_by` are two people, and must be.** The employee who
 * moves the order into «عربون مدفوع» may not be the one who ticks `is_deposit_received`; the rule
 * is enforced in `ConfirmDepositReceipt` against these two columns. Storing the claimer rather
 * than reading the latest transition out of `order_status_transitions` is what lets the API answer
 * «هل يجوز لك التأكيد؟» in the order payload, so the app greys the box with a reason instead of
 * letting somebody tap it and collect a refusal.
 *
 * **Nothing here gates anything.** No status change, no production step and no settlement reads
 * `is_deposit_received`: an order whose deposit nobody has confirmed yet moves through the
 * workshop exactly like one whose deposit was confirmed. Bookkeeping that stops a press is
 * bookkeeping nobody will do — see Docs/orders/ORDER-DEPOSIT-PLAN.md §٣٫٥.
 *
 * Money is `decimal(12,2)` like every other money column on this table, never a float. Nullable
 * with no backfill: every order already in the table was taken before deposits existed, and
 * `false`/null is the honest answer for all of them. The boolean is `NOT NULL` with a default
 * because a three-state «لم يُؤكَّد بعد / لا / نعم» is a distinction nobody asked for.
 *
 * The index is **partial**, over exactly the accountant's own query — the orders that asked for a
 * deposit and have not had it confirmed. Live rows only, like every other index on this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // The expectation, written on entering «انتظار العربون».
            $table->decimal('deposit_expected_amount', 12, 2)->nullable()->after('carrier_settled_amount');
            $table->string('deposit_expected_method', 30)->nullable()->after('deposit_expected_amount');

            // The claim: when the order was said to have been paid, and by whom it was said.
            $table->timestamp('deposit_paid_at')->nullable()->after('deposit_expected_method');

            // `nullOnDelete` throughout, like `ready_message_sent_by`: an employee leaving must
            // not take the record of the work with them. The claim and the confirmation both
            // survive; only the name behind them goes.
            $table->foreignId('deposit_claimed_by')
                ->nullable()
                ->after('deposit_paid_at')
                ->constrained('users')
                ->nullOnDelete();

            // The entry the status change itself created, if it created one — what a walk back
            // to «انتظار العربون» reverses. Null when the money was recorded from the payments
            // screen instead, and that entry is deliberately not this move's to undo.
            $table->foreignId('deposit_payment_id')
                ->nullable()
                ->after('deposit_claimed_by')
                ->constrained('order_payments')
                ->nullOnDelete();

            // The confirmation. Three columns written and cleared together by
            // `ConfirmDepositReceipt`, so the order can never be left half marked.
            $table->boolean('is_deposit_received')->default(false)->after('deposit_payment_id');
            $table->timestamp('deposit_confirmed_at')->nullable()->after('is_deposit_received');
            $table->foreignId('deposit_confirmed_by')
                ->nullable()
                ->after('deposit_confirmed_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        DB::statement(
            'CREATE INDEX orders_deposit_unconfirmed_index ON orders (deposit_paid_at) '
            .'WHERE deposit_expected_amount IS NOT NULL AND is_deposit_received = false AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_deposit_unconfirmed_index');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposit_confirmed_by');
            $table->dropConstrainedForeignId('deposit_payment_id');
            $table->dropConstrainedForeignId('deposit_claimed_by');

            $table->dropColumn([
                'deposit_expected_amount',
                'deposit_expected_method',
                'deposit_paid_at',
                'is_deposit_received',
                'deposit_confirmed_at',
            ]);
        });
    }
};
