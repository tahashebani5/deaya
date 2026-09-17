<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where the goods a shortage purchase bought actually went.
 *
 * **Recording a supply used to be a money note beside the goods; it is now the goods arriving.**
 * The reason is a fact about the order machine that the first design got wrong: an order cannot
 * leave «نواقص» until every `shortage_quantity` is back to zero, and the deduction that follows
 * takes `OrderItem::producedQuantity()` — the **full** ordered amount — off the shelf. So the
 * sacks bought to cover a shortage have to reach the warehouse or the order is refused at
 * «جاهزة» with `OrderStockShortfall`, and if they reach it by some other screen the same purchase
 * is recorded twice: once here and once on whatever document put it on the shelf.
 *
 * Posting the arrival from the supply itself closes both: one purchase, one row, one cost layer —
 * and because the order then draws that layer, the money finally reaches
 * `cost_of_goods_sold.material` in the P&L, which is the only road to that report that exists
 * before the accounting context does. See SHORTAGES-DESIGN.md §٧٫٥.
 *
 * **Both columns are nullable, and the pair is all-or-nothing.** A shortage written down by hand
 * for something the catalogue has never heard of — «شريط لاصق عريض» — has no stock item to post
 * against, so it keeps the old shape: quantity, money, and no movement. That case is an expense
 * with nowhere to go until there is a ledger, and pretending it is stock would be worse than
 * admitting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortage_supplies', function (Blueprint $table) {
            // Where the goods landed. No cascade — a warehouse is retired, never deleted, and a
            // purchase keeps naming the shelf it went to.
            $table->foreignId('warehouse_id')->nullable()->after('method')
                ->constrained('warehouses');

            /*
             * The arrival this purchase posted, and the anchor the reversal works from.
             *
             * `nullOnDelete` rather than cascade for the reason the ledger itself follows:
             * movements are never deleted, so this never fires — and if one somehow were, losing
             * the money row along with it would be the wrong trade.
             */
            $table->foreignId('stock_movement_id')->nullable()->after('warehouse_id')
                ->constrained('stock_movements')->nullOnDelete();
        });

        /*
         * **A purchase that named a warehouse posted a movement, and one that did not, did not.**
         *
         * The two columns are written together by one action inside one transaction, so a row
         * carrying only one of them is a bug rather than a state — most likely a movement posted
         * and then not recorded against its supply, which would leave stock on a shelf that no
         * reversal could ever take back off.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE shortage_supplies
                ADD CONSTRAINT shortage_supplies_arrival_shape CHECK (
                    (warehouse_id IS NULL AND stock_movement_id IS NULL)
                    OR
                    (warehouse_id IS NOT NULL AND stock_movement_id IS NOT NULL)
                )
        SQL);

        // One supply per movement, ever. The same partial shape every unique index in this schema
        // uses — and the thing that makes a double-posted arrival a database error rather than a
        // shelf quietly counted twice.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX shortage_supplies_stock_movement_id_unique
                ON shortage_supplies (stock_movement_id)
                WHERE stock_movement_id IS NOT NULL AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('shortage_supplies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_movement_id');
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
