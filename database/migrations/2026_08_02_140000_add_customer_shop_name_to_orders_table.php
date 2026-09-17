<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The branch an order was going to, snapshotted like everything else about its destination.
 *
 * `orders` already copies `city_name` and `region_name` at the time it is taken, precisely so a
 * renamed or removed row on the delivery map cannot rewrite where an old order said it was
 * going. The shop was the one part of that address left as a bare foreign key — an oversight
 * rather than a decision, and it shows up the moment a customer renames a branch: the order's
 * city and region still read as they did, while the shop silently becomes something else.
 *
 * **Nullable, and it stays nullable.** Plenty of customers sell from one place and name no shop
 * at all, so null here means "no branch was named" — not "we lost it".
 *
 * The backfill takes the shop's *current* name for rows that already exist. That is not the name
 * as of the day those orders were taken, and nothing can recover that — but it is the best
 * answer available, and leaving them null would claim no branch was chosen when one was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_shop_name', 255)->nullable()->after('customer_shop_id');
        });

        DB::statement(
            'UPDATE orders SET customer_shop_name = customer_shops.name
             FROM customer_shops
             WHERE orders.customer_shop_id = customer_shops.id'
        );
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('customer_shop_name');
        });
    }
};
