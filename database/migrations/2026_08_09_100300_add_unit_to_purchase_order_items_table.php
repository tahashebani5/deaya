<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which unit this line's quantity is in — a snapshot of `products.pricing_unit`, copied the same
 * way `order_items.pricing_unit` already is: renaming a product's unit tomorrow must not rewrite
 * what an order raised today says it is in.
 *
 * Added nullable, backfilled from the product every existing line already points at (there is
 * always an answer — a product has always had a `pricing_unit` — unlike cost, which has no
 * historical value to derive), then closed to `NOT NULL`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->string('unit', 20)->nullable()->after('product_variant_id');
        });

        DB::statement(
            'UPDATE purchase_order_items
             SET unit = products.pricing_unit
             FROM product_variants, products
             WHERE product_variants.id = purchase_order_items.product_variant_id
               AND products.id = product_variants.product_id'
        );

        // Raw, not `->change()`: change() restates the whole column definition and quietly drops
        // anything it was not told about. Only the nullability is moving here.
        DB::statement('ALTER TABLE purchase_order_items ALTER COLUMN unit SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('unit');
        });
    }
};
