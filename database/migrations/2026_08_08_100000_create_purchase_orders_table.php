<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order placed with a vendor for stock that has not arrived yet.
 *
 * `new → arrived → completed`, with `cancelled` reachable from either open status — see
 * {@see PurchaseOrderStatus}. `arrived` deliberately covers both "sent, awaiting the vendor" and
 * "some lines partially received": the first {@see StockArrival} posted against a PO is what
 * moves it out of `new`, whether or not anyone ever pressed a "mark as sent" button first.
 *
 * `warehouse_id` is nullable and `nullOnDelete`, the same shape `stock_arrivals.warehouse_id`
 * already uses: a warehouse can genuinely be deleted once empty, and a vendor's ordering history
 * should survive that. `vendor_id` is `cascadeOnDelete` for the same reason it is on
 * `stock_arrivals` — nothing hard-deletes a vendor today, so this is a data-integrity guarantee
 * rather than something expected to fire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            $table->string('status')->default('new')->index();

            $table->date('order_date');

            // When the vendor is expected to deliver. Nullable: not every order has a promised date.
            $table->date('expected_date')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['vendor_id', 'created_at']);
            $table->index(['warehouse_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
