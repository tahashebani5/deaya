<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger row this line's own stock deduction produced — a forward pointer, the same
 * treatment `stock_arrival_items.stock_movement_id` already gets.
 *
 * Written once, by `DeductOrderStock`, and read back by `ReverseOrderStockDeduction` if the order
 * is later cancelled: crediting the exact cost layers a line's fulfillment drew from means first
 * knowing which movement drew them, and `stock_movements.reference_id` alone is not enough to
 * answer that unambiguously — it names the order, not the line, and an order can carry two lines
 * for the same size.
 *
 * Nullable and unbackfilled: null for every line that has not reached printing yet, and for one
 * that predates this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('fulfillment_stock_movement_id')->nullable()
                ->after('warehouse_quantity')
                ->constrained('stock_movements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fulfillment_stock_movement_id');
        });
    }
};
