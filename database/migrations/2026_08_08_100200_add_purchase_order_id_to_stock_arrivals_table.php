<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a shipment back to the {@see PurchaseOrder} it was fulfilling, when there is one.
 *
 * Nullable: most of the arrivals this table already holds were never ordered ahead of time, and
 * an unplanned shipment is still a legitimate arrival. `nullOnDelete`, the same shape
 * `warehouse_id` already uses on this table — the purchase order describes what was bought, not
 * the arrival itself, so the arrival's own history should survive a purchase order being removed.
 *
 * Purely descriptive here. `RecordStockArrival` writes it and nothing in the Vendor context ever
 * reads it to decide anything — the purchase order's own line quantities and status are updated
 * by `PurchaseOrder\Actions\ReceivePurchaseOrder`, one layer up, after this write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_arrivals', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')
                ->nullable()
                ->after('vendor_id')
                ->constrained('purchase_orders')
                ->nullOnDelete();

            $table->index(['purchase_order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_arrivals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });
    }
};
