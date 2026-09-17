<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One line inside a {@see PurchaseOrder} — a size, how much was ordered, and how much of it has
 * arrived so far.
 *
 * `quantity_received` starts at zero and is only ever incremented by
 * {@see ReceivePurchaseOrder}, one {@see StockArrival} at a time — never fillable, the same
 * treatment `stock_arrival_items.stock_movement_id` gets for the same reason: it is a number this
 * table earns by another table actually moving stock, not something a request may set directly.
 *
 * `quantity` precision matches `stock_arrival_items.quantity` — `decimal(12,3)` — because the two
 * are compared against each other line by line, and a mismatched precision would make that
 * comparison lie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();

            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();

            $table->decimal('quantity_ordered', 12, 3);

            $table->decimal('quantity_received', 12, 3)->default(0);

            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['purchase_order_id', 'created_at']);
        });

        // A line ordering nothing explains nothing, the same rule stock_arrival_items.quantity
        // holds. quantity_received has no upper bound here, and is not meant to have one: a
        // supplier who ships more than was ordered is booked in whole — see
        // ReceivePurchaseOrder — so a line legitimately holds more than it asked for.
        DB::statement(
            'ALTER TABLE purchase_order_items
             ADD CONSTRAINT purchase_order_items_quantity_ordered_is_positive CHECK (quantity_ordered > 0)'
        );

        DB::statement(
            'ALTER TABLE purchase_order_items
             ADD CONSTRAINT purchase_order_items_quantity_received_is_not_negative CHECK (quantity_received >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
