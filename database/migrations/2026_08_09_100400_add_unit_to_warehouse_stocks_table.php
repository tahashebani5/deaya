<?php

use App\Domain\Inventory\Actions\ApplyStockChange;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which unit this balance is counted in — a snapshot of `products.pricing_unit`, taken once when
 * the row is first created by {@see ApplyStockChange::increase()}
 * and never re-derived afterwards, the same treatment `purchase_order_items.unit` and
 * `order_items.pricing_unit` already get.
 *
 * Added nullable, backfilled from the product every existing balance already points at, then
 * closed to `NOT NULL`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_stocks', function (Blueprint $table) {
            $table->string('unit', 20)->nullable()->after('product_variant_id');
        });

        DB::statement(
            'UPDATE warehouse_stocks
             SET unit = products.pricing_unit
             FROM product_variants, products
             WHERE product_variants.id = warehouse_stocks.product_variant_id
               AND products.id = product_variants.product_id'
        );

        // Raw, not `->change()`: change() restates the whole column definition and quietly drops
        // anything it was not told about. Only the nullability is moving here.
        DB::statement('ALTER TABLE warehouse_stocks ALTER COLUMN unit SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('warehouse_stocks', function (Blueprint $table) {
            $table->dropColumn('unit');
        });
    }
};
