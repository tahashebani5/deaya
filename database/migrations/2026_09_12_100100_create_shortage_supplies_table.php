<?php

declare(strict_types=1);

use App\Domain\Shortage\Enums\SupplyKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every time a quantity came back against a shortage — what was got, what it cost, and how it
 * was paid for.
 *
 * **A ledger, not a balance**, for the reason `order_payments` is one: the business asked for
 * partial supply across several goes, and «٢٠ كجم بـ٥٠٠، ثم ١٠ كجم بـ٢٦٠» is two events. A pair
 * of running totals on `shortages` could hold the sum but could never answer «من اشترى، ومتى،
 * وبكم» — which is the whole of §٩ of the brief, the table the detail screen draws.
 *
 * So rows are **written once and never edited**. There is no route that updates or deletes one,
 * and a correction is a further row pointing back at the one it undoes. `shortages.total_paid`
 * and `supplied_quantity` are restated from this table by `RecalculateShortageTotals` afterwards,
 * so the cache and the ledger cannot come apart — and that is what makes «منع تكرار الخصم
 * المالي» structural rather than a rule somebody has to remember.
 *
 * `quantity` and `amount` are always positive. Whether a row adds or undoes is
 * `reverses_supply_id`'s job, so a sum over the table cannot be quietly wrong because one row was
 * stored negative.
 *
 * **No accounting entry is written from here, and that is a dated decision rather than an
 * omission.** `app/Domain/Accounting` does not exist — ACCOUNTING-DESIGN.md is still a proposal
 * waiting on its §١١ — so this table is a subsidiary ledger exactly as `order_payments` was
 * before the ledger existed. The day it lands, one posting rule reads these rows at one call
 * site. See SHORTAGES-DESIGN §٧٫٤.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shortage_supplies', function (Blueprint $table) {
            $table->id();

            // Cascade: a shortage that should never have been written takes its own entries with
            // it. Unlike an order's transitions (see ORDER-DELETE-AND-ARCHIVE §٥) nothing else
            // reads these rows, so following the parent costs nothing.
            $table->foreignId('shortage_id')->constrained('shortages')->cascadeOnDelete();

            $table->string('kind', 30)->default(SupplyKind::Purchased->value);

            // Three decimals like the requirement it pays down, two on the money like every other
            // money column here. Never a float: these are summed.
            $table->decimal('quantity', 12, 3);

            // Null on a kind that bought nothing — see the shape CHECK below and `SupplyKind`.
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('method', 20)->nullable();

            // The transfer number, when the method has one — `PaymentMethod::suggestsReference()`
            // is the hint, never the rule.
            $table->string('reference', 100)->nullable();

            /*
             * **The receipt, stored the way `order_payments` stores one** — disk and path per
             * row and never a URL, on a private disk, so moving to S3 is a config change.
             *
             * Optional on every method here, which is the one place this table deliberately
             * parts from `order_payments`: a transfer *to* a customer is proved by the paper
             * they send us, while a sack bought from the shop next door often has no document at
             * all. Refusing the entry for want of one would push the purchase onto paper, which
             * is the thing this feature exists to end.
             */
            $table->string('receipt_disk', 50)->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_original_filename')->nullable();
            $table->unsignedBigInteger('receipt_size_bytes')->nullable();
            $table->string('receipt_checksum', 64)->nullable();

            // When it was got, not when it was typed — `created_at` already answers the second,
            // and a purchase made on Thursday is entered on Saturday.
            $table->date('occurred_on');

            $table->text('notes')->nullable();

            // Filled on a reversal alone, and it is what makes one readable: the mistake and its
            // correction are one row apart instead of two numbers a reader pairs up by eye.
            $table->foreignId('reverses_supply_id')->nullable()
                ->constrained('shortage_supplies')->nullOnDelete();

            // Nullable so a seeder, a console command or the sync itself can write an entry
            // without inventing a user; everything through the API carries the signed-in one,
            // stamped rather than mass-assigned.
            $table->foreignId('recorded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes()->index();

            // The shortage's own ledger, which is how this table is read on a screen.
            $table->index(['shortage_id', 'occurred_on']);

            // «كم صرفنا على النواقص هذا الشهر، وبأي طريقة؟» — the report this shape allows later.
            $table->index(['method', 'occurred_on']);
        });

        /*
         * **The invariants live in the database, not only in validation** — RULES.md §8. A
         * `unique` rule loses to two concurrent requests that both pass the existence check
         * before either commits; a unique index does not.
         */

        // One reversal per entry, ever. Partial, so a soft-deleted row does not hold the slot.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX shortage_supplies_reverses_supply_id_unique
                ON shortage_supplies (reverses_supply_id)
                WHERE reverses_supply_id IS NOT NULL AND deleted_at IS NULL
        SQL);

        // An entry of nothing is not an event.
        DB::statement(<<<'SQL'
            ALTER TABLE shortage_supplies
                ADD CONSTRAINT shortage_supplies_quantity_positive CHECK (quantity > 0)
        SQL);

        // Money may be absent, but it may not be zero: a purchase that cost nothing is a purchase
        // nobody made, and it would sit in the total that answers «كم صرفنا؟».
        DB::statement(<<<'SQL'
            ALTER TABLE shortage_supplies
                ADD CONSTRAINT shortage_supplies_amount_positive CHECK (amount IS NULL OR amount > 0)
        SQL);

        /*
         * **«الكمية + القيمة + طريقة الدفع» stated once, where every writer meets it.**
         *
         * A purchase names both its price and how it was paid; a quantity that merely turned up
         * from the order names neither, and inventing a zero for it would put a free purchase in
         * the shortage's total. `SupplyKind::requiresPayment()` says the same thing in PHP and
         * the FormRequest says it a third time — the three-layer arrangement RULES.md §8 asks
         * for, where validation gives the readable 422 and this gives the guarantee.
         */
        $purchased = SupplyKind::Purchased->value;

        DB::statement(<<<SQL
            ALTER TABLE shortage_supplies
                ADD CONSTRAINT shortage_supplies_payment_shape CHECK (
                    (kind = '{$purchased}' AND amount IS NOT NULL AND method IS NOT NULL)
                    OR
                    (kind <> '{$purchased}' AND amount IS NULL AND method IS NULL)
                )
        SQL);

        /*
         * A reversal undoes a purchase, never an arrival.
         *
         * `resolved_externally` rows are written by the sync as a consequence of the order line
         * moving, so «undoing» one means correcting the order — and a reversal here would leave
         * this table disagreeing with the line it was derived from. The one road back is the
         * order screen, which re-runs the sync.
         */
        DB::statement(<<<SQL
            ALTER TABLE shortage_supplies
                ADD CONSTRAINT shortage_supplies_reversal_is_a_purchase CHECK (
                    reverses_supply_id IS NULL OR kind = '{$purchased}'
                )
        SQL);

        /*
         * **`order_payments_transfer_needs_receipt` has no twin here, on purpose.**
         *
         * That constraint makes `PaymentMethod::BankTransfer` carry a file, because a disputed
         * transfer *from a customer* is one person's word against another's. The money here moves
         * the other way and against a shop rather than a customer, so there is no counterparty to
         * dispute it and often no document at all — and refusing the entry for want of one pushes
         * the purchase onto paper, which is what this feature exists to end. See the receipt
         * block above.
         */
    }

    public function down(): void
    {
        Schema::dropIfExists('shortage_supplies');
    }
};
