<?php

use App\Domain\PurchaseOrder\Actions\ReceivePurchaseOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * You buy what you stock.
 *
 * A received purchase order line becomes a `StockBatch`, and a batch is keyed on the shelf — so a
 * line naming a size could not open one without guessing which shelf the size meant. Both tables
 * move to `stock_item_id` together, because the arrival sits between them.
 *
 * **Per-size pricing is not lost, because the size lives on the stock item.** «كيس شحن 25*35» and
 * «كيس شحن 35*40» are two rows in `stock_items`, so an order for 300 of one and 400 of the other
 * is still two lines at two prices, opening two cost layers on two shelves. What can no longer be
 * expressed is two *products* at the same size as two lines — and that was never a real purchase,
 * only the catalogue's split leaking into the vendor's invoice.
 *
 * **The unique index below is new, and it closes a hole this change would have widened.**
 * `purchase_order_items` has only ever had a `distinct` validation rule, never an index —
 * exactly the half-measure RULES.md §8 warns about, since two concurrent requests both pass a
 * check neither has committed past. {@see ReceivePurchaseOrder} keys its lines with
 * `keyBy('stock_item_id')`, and `keyBy` silently keeps the last of any duplicate: a second line
 * that slipped through would lose its receipt with no error anywhere. Reaching a duplicate used
 * to need the identical variant twice; now any two products at one size collapse to one item, so
 * it is genuinely reachable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            // Nullable, backfilled, then made NOT NULL — see the note in
            // `rekey_warehouse_stocks_to_stock_items`. A no-op on an empty database.
            $table->foreignId('stock_item_id')->nullable()->after('purchase_order_id')
                ->constrained('stock_items')->cascadeOnDelete();
        });

        Schema::table('stock_arrival_items', function (Blueprint $table) {
            $table->foreignId('stock_item_id')->nullable()->after('stock_arrival_id')
                ->constrained('stock_items')->cascadeOnDelete();
        });

        foreach (['purchase_order_items', 'stock_arrival_items'] as $lines) {
            DB::statement(
                "UPDATE {$lines}
                 SET stock_item_id = v.stock_item_id
                 FROM product_variants v
                 WHERE v.id = {$lines}.product_variant_id
                   AND v.stock_item_id IS NOT NULL"
            );
        }

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_item_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('product_variant_id');
        });

        Schema::table('stock_arrival_items', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_item_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('product_variant_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX purchase_order_items_one_line_per_item
             ON purchase_order_items (purchase_order_id, stock_item_id) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS purchase_order_items_one_line_per_item');

        Schema::table('stock_arrival_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_item_id');

            $table->foreignId('product_variant_id')->after('stock_arrival_id')
                ->constrained('product_variants')->cascadeOnDelete();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_item_id');

            $table->foreignId('product_variant_id')->after('purchase_order_id')
                ->constrained('product_variants')->cascadeOnDelete();
        });
    }
};
