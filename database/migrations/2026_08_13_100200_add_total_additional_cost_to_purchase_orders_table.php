<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The sum of an order's additional costs (delivery, unloading, customs, ...), cached the same
 * way `total_amount` already is — written once by {@see RecalculatePurchaseOrderTotal}, never
 * trusted from a client-side sum. `total_amount` itself keeps its existing role but now sums
 * each line's `final_total_cost` instead of its old `total_cost`, so it already includes this
 * figure — `total_additional_cost` exists so the split is visible rather than folded away.
 *
 * Nullable for the same reason `total_amount` is: an order raised before this feature has no
 * additional-cost rows to sum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->decimal('total_additional_cost', 14, 2)->nullable()->after('total_amount');
        });

        DB::statement(
            'ALTER TABLE purchase_orders
             ADD CONSTRAINT purchase_orders_total_additional_cost_not_negative CHECK (total_additional_cost >= 0)'
        );
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('total_additional_cost');
        });
    }
};
