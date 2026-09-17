<?php

use App\Domain\Inventory\Actions\ConsumeStockBatchesFifo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cost layers follow the balance they add up to.
 *
 * `SUM(quantity_remaining)` for a `(warehouse_id, stock_item_id)` must equal that shelf's
 * `warehouse_stocks.quantity` — the invariant `StockBatchLedgerTest` enforces — so the key on
 * both sides has to be the same thing. It now is.
 *
 * **This is where the change pays for itself.** Two products sharing one pile used to keep two
 * private FIFO stacks over stock that was bought once, on one purchase order line, at one price;
 * each drifted to its own average and neither described what the shelf actually cost. One stack
 * per shelf is both cheaper to reason about and simply true.
 *
 * {@see ConsumeStockBatchesFifo} is unchanged beyond the column name: still oldest `received_at`
 * first, still never `created_at`, so stock does not get younger by moving shelves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_batches', function (Blueprint $table) {
            // Nullable, backfilled, then made NOT NULL — see the note in
            // `rekey_warehouse_stocks_to_stock_items`. A no-op on an empty database.
            $table->foreignId('stock_item_id')->nullable()->after('warehouse_id')
                ->constrained('stock_items')->cascadeOnDelete();
        });

        DB::statement(
            'UPDATE stock_batches
             SET stock_item_id = v.stock_item_id
             FROM product_variants v
             WHERE v.id = stock_batches.product_variant_id
               AND v.stock_item_id IS NOT NULL'
        );

        Schema::table('stock_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_item_id')->nullable(false)->change();

            $table->dropIndex(['warehouse_id', 'product_variant_id', 'received_at']);
            $table->dropConstrainedForeignId('product_variant_id');

            // FIFO always asks "which of this item, in this warehouse, still has something left,
            // oldest first" — this is that query's index.
            $table->index(['warehouse_id', 'stock_item_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_batches', function (Blueprint $table) {
            $table->dropIndex(['warehouse_id', 'stock_item_id', 'received_at']);
            $table->dropConstrainedForeignId('stock_item_id');

            $table->foreignId('product_variant_id')->after('warehouse_id')
                ->constrained('product_variants')->cascadeOnDelete();

            $table->index(['warehouse_id', 'product_variant_id', 'received_at']);
        });
    }
};
