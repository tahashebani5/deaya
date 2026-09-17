<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a وسيط size costs us — «سعر التكلفة».
 *
 * **On the size, not on the product**, because the thing it has to be compared with is on the
 * size: a sale price is a tier on `product_price_tiers`, keyed to a variant. A single number on
 * `products` could not be set against the price of a product with three sizes, and the first
 * report to divide one by the other would be dividing two different things.
 *
 * **Nullable, and meaningful only under an `outsourced` heading.** What we make ourselves is
 * costed from what it consumed — `order_items.material_cost` from the shelf, and
 * `production_cost_entries` for labour and overhead — so a typed number there would be a second,
 * unowned answer to a question already answered. The request refuses it; see
 * `StoreProductRequest::rejectCostPriceOnGoodsWeMakeOurselves()`.
 *
 * Three decimal places and a non-negative check, matching `product_price_tiers.unit_price` beside
 * it: the two are read together and must round the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('cost_price', 14, 3)->nullable()->after('stock_item_id');
        });

        DB::statement(
            'ALTER TABLE product_variants ADD CONSTRAINT product_variants_cost_price_not_negative CHECK (cost_price >= 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE product_variants DROP CONSTRAINT product_variants_cost_price_not_negative');

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('cost_price');
        });
    }
};
