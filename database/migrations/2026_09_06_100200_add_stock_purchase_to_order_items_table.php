<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a printed line paid for its material, and what that material actually cost.
 *
 * Until today those were one number. A line that prints on an investor's plain bags now buys
 * them off the shelf at the deal's سعر السادة, so `material_cost` becomes **what the press
 * paid** — the figure its own profit must carry — and `material_cost_actual` keeps **what the
 * goods cost the business**, which is what a company-wide profit is still computed from. The two
 * are equal on every line that bought nothing, which is every line that existed before this.
 *
 * `stock_purchased_at` is the fact the other two are read against, and it earns its own column
 * by answering three questions no other column can:
 *
 * - **The order's delivery no longer pays this line's investor** — he was paid when the goods
 *   left. `OrderDealSlices` skips its priced draws, or he is paid twice for one kilo.
 * - **This line no longer blocks closing the deal** — `DealOrdersInFlightQuery` waits only on
 *   money that is not settled yet, and this line's is.
 * - **A cancellation returns its goods to the company, not to the deal** —
 *   `ReverseOrderStockDeduction` reads it to decide, «استلم الزبون ما استلمش المطبعة تتحمّل».
 *
 * Deriving it instead — «is this line's product filed under مطبوعة, and did it draw a priced
 * layer» — would ask Catalog and Inventory the same question in three places and answer it
 * differently the day a category is re-filed. What happened on the day the stock left is a fact,
 * and facts are stamped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('material_cost_actual', 14, 2)->nullable()->after('material_cost');
            $table->timestamp('stock_purchased_at')->nullable()->after('material_cost_actual');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE order_items
            ADD CONSTRAINT order_items_material_cost_actual_positive
            CHECK (material_cost_actual IS NULL OR material_cost_actual >= 0)
        SQL);

        // Every line costed before today paid exactly what its goods cost. Backfilled rather
        // than left null so a company-wide cost total may read one column for every row.
        DB::statement('UPDATE order_items SET material_cost_actual = material_cost WHERE material_cost IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_material_cost_actual_positive');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['stock_purchased_at', 'material_cost_actual']);
        });
    }
};
