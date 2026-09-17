<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which road this order walks — see `OrderFlow`.
 *
 * **A column rather than a question asked of the lines on every read**, for two reasons and the
 * second is the one that matters.
 *
 * The cheap reason: `Order::availableTransitionsFor()` and `Order::progress()` are computed for
 * *every row* of the orders list, and deriving the flow live would mean
 * `items.product.productCategory.parent` eager-loaded on a screen that is already the busiest in
 * the app.
 *
 * The real reason: **the flow an order was taken under must not change under the staff working
 * it.** A category flagged «سادة» this afternoon would otherwise re-route every open order that
 * touches it — a job someone has already sent to the press would lose «قيد الطباعة» from its own
 * progress bar and start drawing itself as a detour. This is the same reasoning that makes
 * `city_name` and `customer_shop_name` snapshots on this table: what the order said on the day
 * survives the list being edited.
 *
 * Defaults to `standard`, which is what every order written before today actually was — so no
 * backfill is needed and none is a lie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Beside `fulfilment_type`, the other server-decided fact about how this particular
            // order is carried out. Both are read together by `OrderStatus::mainLine()`.
            $table->string('production_flow', 20)
                ->default('standard')
                ->after('fulfilment_type');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('production_flow');
        });
    }
};
