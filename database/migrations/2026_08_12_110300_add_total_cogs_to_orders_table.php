<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The order-level cache of `SUM(order_items.cogs)` — the same relationship `items_total` has with
 * `order_items.line_total`. Gross profit itself is never stored: it is `grand_total - total_cogs`,
 * computed at report time from two already-cached columns rather than a third cache to keep in
 * sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('total_cogs', 14, 2)->nullable()->after('grand_total');
        });

        DB::statement(
            'ALTER TABLE orders ADD CONSTRAINT orders_total_cogs_not_negative CHECK (total_cogs >= 0)'
        );
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('total_cogs');
        });
    }
};
