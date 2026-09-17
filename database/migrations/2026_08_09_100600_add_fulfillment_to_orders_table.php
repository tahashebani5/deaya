<?php

use App\Domain\Order\Actions\ChangeOrderStatus;
use App\Domain\Order\Actions\DeductOrderStock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an order's stock came from, and whether it has come out of the warehouse yet.
 *
 * `stock_deducted_at` is the guard {@see ChangeOrderStatus} checks
 * before calling {@see DeductOrderStock} — `printing` is re-enterable
 * (a reprint goes `ready` → `printing` or `shortage` → `printing`), and stock must leave the
 * warehouse exactly once per order, not once per visit. Stamped, never cleared: unlike
 * `printing_started_at`, which is allowed to describe only the most recent visit, this column's
 * entire job is to remember the *first* one.
 *
 * `fulfillment_warehouse_id` is the same "denormalise the fact onto the order" treatment
 * `shipping_company_id` already gets — nullable and `nullOnDelete`, the same shape
 * `purchase_orders.warehouse_id` uses, because a warehouse can genuinely be deleted once empty and
 * an order's own history should survive that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('stock_deducted_at')->nullable()->after('printing_started_at');

            $table->foreignId('fulfillment_warehouse_id')
                ->nullable()
                ->after('stock_deducted_at')
                ->constrained('warehouses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fulfillment_warehouse_id');
            $table->dropColumn('stock_deducted_at');
        });
    }
};
