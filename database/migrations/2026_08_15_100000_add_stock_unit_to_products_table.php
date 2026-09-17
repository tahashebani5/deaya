<?php

use App\Domain\Inventory\Actions\SetStockUnit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the warehouse counts this product in — independent of `pricing_unit`, what the customer
 * is charged by. The two agree for most products, but a product bought in bulk by weight and
 * sold by the piece needs them to differ: `pricing_unit` stays `piece` so an order cannot ask
 * for half a bag, while `stock_unit` becomes `kilogram` so a warehouse movement can.
 *
 * Added nullable, backfilled from `pricing_unit` — every existing product starts with the two
 * equal, exactly today's behaviour — then closed to `NOT NULL`. Changed afterwards only through
 * {@see SetStockUnit}, which is what keeps `warehouse_stocks.unit`/`stock_batches.unit` in step
 * with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('stock_unit', 20)->nullable()->after('pricing_unit');
        });

        DB::statement('UPDATE products SET stock_unit = pricing_unit');

        // Raw, not `->change()`: change() restates the whole column definition and quietly drops
        // anything it was not told about. Only the nullability is moving here.
        DB::statement('ALTER TABLE products ALTER COLUMN stock_unit SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('stock_unit');
        });
    }
};
