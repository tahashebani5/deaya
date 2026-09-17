<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The deal's سعر السادة, stamped onto every cost layer it financed.
 *
 * **A copy, and deliberately so — the same copy `investor_deal_id` beside it already is.** Two
 * reasons, and either alone would settle it:
 *
 * - **Orders may not ask Investment anything** (RULES §3: the dependency runs the other way, and
 *   a direct call closes a loop the container cannot build). `DeductOrderStock` has to price a
 *   printed line's material at what the press paid, and the only context it may ask is
 *   Inventory. So the price rides on the layer, exactly as the deal's identity does, and
 *   Inventory copies it without reading it — see `ConsumptionBreakdownQuery`.
 * - **It freezes at the right moment.** A layer bought under a 32 agreement keeps selling at 32
 *   after the next lorry is funded at 35. Read live off `investor_deals`, one edit would restate
 *   stock that is already on the shelf under terms nobody agreed to.
 *
 * Written in the same three places `investor_deal_id` is — an arrival, a transfer's destination
 * (`relocateBatches`), and a revaluation's child layer — and a missed copy is just as silent
 * there, which is why each of them has a test.
 *
 * Null on almost every layer, and null means «not for sale to the press at a fixed price»: the
 * company's own stock, and a deal funded before this existed. Such a layer is costed to an order
 * at what it actually cost, exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_batches', function (Blueprint $table) {
            $table->decimal('printing_sale_price', 12, 3)->nullable()->after('investor_deal_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE stock_batches
            ADD CONSTRAINT stock_batches_printing_sale_price_positive
            CHECK (printing_sale_price IS NULL OR printing_sale_price > 0)
        SQL);

        // A price with nobody to pay it is a contradiction: the margin it creates has no owner,
        // and every reader of this column reaches for the deal in the same breath.
        DB::statement(<<<'SQL'
            ALTER TABLE stock_batches
            ADD CONSTRAINT stock_batches_printing_price_needs_a_deal
            CHECK (printing_sale_price IS NULL OR investor_deal_id IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_batches DROP CONSTRAINT IF EXISTS stock_batches_printing_price_needs_a_deal');
        DB::statement('ALTER TABLE stock_batches DROP CONSTRAINT IF EXISTS stock_batches_printing_sale_price_positive');

        Schema::table('stock_batches', function (Blueprint $table) {
            $table->dropColumn('printing_sale_price');
        });
    }
};
