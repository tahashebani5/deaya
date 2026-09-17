<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planned cost on a purchase order line.
 *
 * `unit_cost` is what the vendor was told this line would cost per unit — typed by whoever raised
 * the order, the same way `OrderItem::unit_price` is typed for a product priced «حسب الطلب»,
 * because there is no catalogue price for what *we* pay a vendor. `total_cost` is never typed: it
 * is `unit_cost * quantity_ordered`, computed once by the domain layer, the same relationship
 * `order_items.unit_price`/`line_total` already have.
 *
 * Both nullable, and stay that way for every row that predates this migration — there is no
 * historical cost to backfill them with, unlike a unit, which can be derived from the product.
 * `>= 0` rather than `> 0`: a line can be a free replacement from the vendor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 3)->nullable()->after('quantity_received');
            $table->decimal('total_cost', 14, 2)->nullable()->after('unit_cost');
        });

        DB::statement(
            'ALTER TABLE purchase_order_items
             ADD CONSTRAINT purchase_order_items_unit_cost_not_negative CHECK (unit_cost >= 0)'
        );

        DB::statement(
            'ALTER TABLE purchase_order_items
             ADD CONSTRAINT purchase_order_items_total_cost_not_negative CHECK (total_cost >= 0)'
        );
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'total_cost']);
        });
    }
};
