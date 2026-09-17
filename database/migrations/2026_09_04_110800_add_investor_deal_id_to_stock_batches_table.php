<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Blueprint as B;
use Illuminate\Support\Facades\Schema;

/**
 * **The one column the whole investor feature rests on.**
 *
 * A cost layer already knows what a quantity of stock cost and how much of it is left, and
 * `ConsumeStockBatchesFifo` already draws from the oldest layer first by the date the stock
 * actually arrived, writing one `stock_batch_consumptions` row per layer it touched with the
 * cost frozen at that moment. Marking the layer with whoever financed it turns all of that,
 * unchanged, into per-deal attribution: the sales path is not edited, the FIFO query is not
 * edited, and the employee never chooses anything.
 *
 * Nullable, and null is the normal case — it means the company financed this stock, which is
 * true of every layer that exists today and of most that ever will.
 *
 * **It must be copied in exactly three places**, and a missed copy is silent: it turns a deal's
 * stock into company stock while every quantity in the system stays perfectly correct. Those
 * places are `ApplyStockChange::openBatch()` (arrival and increasing adjustment),
 * `relocateBatches()` (a transfer's destination layer) and
 * `RevalueStockBatch::splitOffRemainder()` (the child row). The transfer and revaluation
 * scenarios each have a test whose only job is to catch that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_batches', function (Blueprint $table) {
            $table->foreignId('investor_deal_id')->nullable()
                ->constrained('investor_deals')->nullOnDelete();

            // «ما الذي تملكه هذه الصفقة الآن؟» ordered the way the deal screen reads it.
            $table->index(['investor_deal_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_batches', function (B $table) {
            $table->dropIndex(['investor_deal_id', 'received_at']);
            $table->dropConstrainedForeignId('investor_deal_id');
        });
    }
};
