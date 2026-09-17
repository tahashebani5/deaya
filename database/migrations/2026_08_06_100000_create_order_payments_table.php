<?php

declare(strict_types=1);

use App\Domain\Order\Enums\OrderPaymentType;
use App\Domain\Order\Enums\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every movement of money against an order — what was paid, what was given back, and what was
 * entered by mistake.
 *
 * **A ledger, not a balance.** The obvious design is a `paid_amount` column that goes up and
 * down, and it was rejected for one reason: the business asked for financial entries to be
 * reversible, and a column that holds a total cannot be reversed because it never recorded what
 * it was made of. A clerk who typed 500 instead of 50 leaves nothing behind to undo, and "when
 * was the deposit paid, and by which method?" has no row to answer from.
 *
 * So rows are **written once and never edited**. There is no route that updates or deletes one,
 * and a correction is a further row that points back at the one it undoes — the same rule
 * `stock_movements` follows, for the same reason: a ledger you can rewrite explains nothing.
 *
 * `amount` is always positive. Which direction it moves is {@see OrderPaymentType}'s job, so a
 * sum over the table cannot be quietly wrong because one row was stored negative.
 *
 * The soft-delete column is here because every table in this schema carries one and a test
 * enforces that — not because anything removes a row. If one ever were removed, `orders.paid_amount`
 * would stop matching the sum of this table, which `OrderPaymentTest` asserts on every path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            $table->string('type', 20);

            // Two places, like every other money column here. Never a float: these are summed,
            // and a sum of floats is a number that is nearly right.
            $table->decimal('amount', 12, 2);

            // Required on a payment and a refund; null on a reversal, which moved no money and
            // so has no method to name. Enforced by the CHECK below rather than by validation
            // alone, so a console command cannot write a shape the API refuses.
            $table->string('method', 20)->nullable();

            // The receipt or transfer number, when there is one.
            $table->string('reference', 100)->nullable();

            /*
             * **The scanned receipt — required for a bank transfer.** (PDF only at first;
             * images joined on 2026-08-22, with no schema change — only the media rules moved.)
             *
             * Cash is witnessed at the counter and a card leaves a trail at the bank. A transfer
             * is neither: what proves it happened is a piece of paper the customer sends, and
             * without it a disputed transfer is one person's word. So the file is part of the
             * entry rather than an attachment somebody may get round to.
             *
             * Stored the way the media layer already stores a customer's artwork: the disk and
             * the path per row and **never a URL**, so moving to S3 is a config change with no
             * migration and no rewritten rows. The disk is private — a receipt carries a
             * customer's bank details.
             */
            $table->string('receipt_disk', 50)->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_original_filename')->nullable();
            $table->unsignedBigInteger('receipt_size_bytes')->nullable();

            // sha256 of the bytes. Not a uniqueness rule — the same receipt legitimately covers
            // two entries when a customer pays twice off one transfer — but the answer to
            // "is this the same file the customer sent last time?" without opening either.
            $table->string('receipt_checksum', 64)->nullable();

            /*
             * **When the money moved, not when somebody typed it in.** `created_at` already
             * answers the second question, and the two genuinely differ: a deposit taken on
             * Thursday evening is entered on Saturday morning, and a cash report built on the
             * entry time would put it in the wrong day.
             */
            $table->timestamp('paid_at');

            $table->text('notes')->nullable();

            /*
             * Filled on a reversal alone, and it is what makes a reversal readable: the mistake
             * and its correction are one row apart instead of two numbers a reader has to pair
             * up by eye.
             *
             * A refund deliberately does *not* point at a payment. Money handed back may have
             * been collected across three of them, and making somebody pick one would put a
             * fact in the ledger that nobody could stand behind.
             */
            $table->foreignId('reverses_payment_id')->nullable()
                ->constrained('order_payments')->nullOnDelete();

            // Nullable so a seeder or a console command can write an entry without inventing a
            // user; everything through the API carries the signed-in one, stamped rather than
            // mass-assigned so no payload can attribute a collection to somebody else.
            $table->foreignId('recorded_by')->nullable()->constrained('users');

            $table->timestamps();
            $table->softDeletes()->index();

            // The order's own ledger, which is how this table is read on a screen.
            $table->index(['order_id', 'paid_at']);

            // "What came into the drawer this week" — the report this shape exists to make
            // possible later.
            $table->index(['type', 'paid_at']);
        });

        /*
         * **The invariants live in the database, not only in validation.**
         *
         * A `unique` rule loses to two concurrent requests that both pass the existence check
         * before either commits; a unique index does not. Both are written: validation gives the
         * readable 422, the index is the guarantee. Same rule as every other table here — see
         * RULES.md §8.
         */

        // One reversal per entry, ever. Partial, because a soft-deleted row must not hold the
        // slot — the same shape every other unique index in this schema uses.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX order_payments_reverses_payment_id_unique
                ON order_payments (reverses_payment_id)
                WHERE reverses_payment_id IS NOT NULL AND deleted_at IS NULL
        SQL);

        // An entry of nothing is not an event. Guarded here as well as in the domain, so no
        // path can write one.
        DB::statement(<<<'SQL'
            ALTER TABLE order_payments
                ADD CONSTRAINT order_payments_amount_positive CHECK (amount > 0)
        SQL);

        // The shape rule, stated once where every writer meets it: a reversal names the row it
        // undoes and no method; a payment or a refund names a method and undoes nothing.
        $reversal = OrderPaymentType::Reversal->value;

        DB::statement(<<<SQL
            ALTER TABLE order_payments
                ADD CONSTRAINT order_payments_shape CHECK (
                    (type = '{$reversal}' AND reverses_payment_id IS NOT NULL AND method IS NULL)
                    OR
                    (type <> '{$reversal}' AND reverses_payment_id IS NULL AND method IS NOT NULL)
                )
        SQL);

        /*
         * A transfer without its receipt is one person's word.
         *
         * `IS DISTINCT FROM` rather than `<>`, because a reversal's method is NULL and `NULL <>
         * 'bank_transfer'` evaluates to NULL — which a CHECK accepts, but only by accident. The
         * null-safe operator says what is meant: every row whose method is not a transfer is
         * fine, and one whose method *is* must carry a file.
         */
        $transfer = PaymentMethod::BankTransfer->value;

        DB::statement(<<<SQL
            ALTER TABLE order_payments
                ADD CONSTRAINT order_payments_transfer_needs_receipt CHECK (
                    method IS DISTINCT FROM '{$transfer}' OR receipt_path IS NOT NULL
                )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
