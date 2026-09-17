<?php

use App\Domain\PurchaseOrder\Actions\ReceivePurchaseOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a received line actually cost, carried over from the purchase order it fulfils.
 *
 * Stays null for the (majority) of arrivals that were never ordered ahead of time — the same
 * nullability `purchase_order_id` already has on `stock_arrivals` for the same reason. Populated
 * only by {@see ReceivePurchaseOrder}, which copies the ordering
 * line's `unit_cost` and prices `total_cost` against what this shipment actually delivered — which
 * can be less than the order line's own `total_cost` on a partial receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_arrival_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 3)->nullable()->after('quantity');
            $table->decimal('total_cost', 14, 2)->nullable()->after('unit_cost');
        });

        DB::statement(
            'ALTER TABLE stock_arrival_items
             ADD CONSTRAINT stock_arrival_items_unit_cost_not_negative CHECK (unit_cost >= 0)'
        );

        DB::statement(
            'ALTER TABLE stock_arrival_items
             ADD CONSTRAINT stock_arrival_items_total_cost_not_negative CHECK (total_cost >= 0)'
        );
    }

    public function down(): void
    {
        Schema::table('stock_arrival_items', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'total_cost']);
        });
    }
};
