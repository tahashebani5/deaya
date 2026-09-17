<?php

use App\Domain\Inventory\Actions\ApplyStockChange;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A balance is of a *shelf*, not of a size.
 *
 * `product_variant_id` gave every product its own private pile of what was physically one pile:
 * كيس شحن سادة 25*35 and كيس شحن مطبوع 25*35 each had a row, and an order drawing on both weighed
 * itself against two of them. The key becomes `stock_item_id` and the two rows become one.
 *
 * **Nothing is merged, and that is still the rule.** There is no correct automatic answer to
 * "which of these two balances was the real pile" — adding them is a guess, keeping one loses
 * stock, and deciding is a business call, not a silent side effect of a schema change
 * (RULES.md §8). This used to enforce that by refusing to run at all on a populated database,
 * which also cost every existing row. It is enforced one step earlier now: the backfill in
 * `backfill_stock_items_for_existing_variants` mints one shelf **per existing (product, size)**,
 * so each balance is repointed at the same physical thing and no two are ever added together.
 * Two products that really do share a pile still arrive here as two shelves, and joining them is
 * the deliberate act the groups exist for.
 *
 * The unique index is the reason the whole context is safe: {@see ApplyStockChange} locks exactly
 * one row per (warehouse, item) and increments it, and a duplicate would let two movements update
 * different rows and lose one of them. It is rebuilt under the new key and renamed with it —
 * PostgreSQL keeps an index's original name through a column change, and a name still saying
 * "variant" would be a lie the next reader has to disprove.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS warehouse_stocks_one_row_per_variant_per_warehouse');

        Schema::table('warehouse_stocks', function (Blueprint $table) {

            // **Nullable, backfilled, then made NOT NULL.** Added NOT NULL in one step this refused
            // to run on any database that already held rows — see
            // `backfill_stock_items_for_existing_variants`, which mints one shelf per existing
            // (product, size) so every row here can be repointed at the same physical thing it
            // already pointed at. On an empty database the backfill selects nothing and the column
            // lands NOT NULL exactly as before, which is why the test suite never saw a difference.
            $table->foreignId('stock_item_id')->nullable()->after('warehouse_id')
                ->constrained('stock_items')->cascadeOnDelete();
        });

        DB::statement(
            'UPDATE warehouse_stocks
             SET stock_item_id = v.stock_item_id
             FROM product_variants v
             WHERE v.id = warehouse_stocks.product_variant_id
               AND v.stock_item_id IS NOT NULL'
        );

        Schema::table('warehouse_stocks', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_item_id')->nullable(false)->change();

            $table->dropIndex(['warehouse_id', 'product_variant_id']);
            $table->dropConstrainedForeignId('product_variant_id');

            // Serves the per-warehouse listing, which is the only way this table is read.
            $table->index(['warehouse_id', 'stock_item_id']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX warehouse_stocks_one_row_per_item_per_warehouse
             ON warehouse_stocks (warehouse_id, stock_item_id) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS warehouse_stocks_one_row_per_item_per_warehouse');

        Schema::table('warehouse_stocks', function (Blueprint $table) {
            $table->dropIndex(['warehouse_id', 'stock_item_id']);
            $table->dropConstrainedForeignId('stock_item_id');

            $table->foreignId('product_variant_id')->after('warehouse_id')
                ->constrained('product_variants')->cascadeOnDelete();

            $table->index(['warehouse_id', 'product_variant_id']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX warehouse_stocks_one_row_per_variant_per_warehouse
             ON warehouse_stocks (warehouse_id, product_variant_id) WHERE deleted_at IS NULL'
        );
    }
};
