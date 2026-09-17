<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One line inside a {@see StockArrival} — a size and how much of it arrived.
 *
 * `stock_movement_id` is the ledger row this line produced: {@see RecordStockArrival} posts each
 * line through `InventoryService::recordMovement()` before writing the item, so the two are
 * created together and this column is never null in practice. Not fillable, and not the thing
 * that moves the balance — the movement it points at already did that, under
 * `InventoryService`'s own lock. This is purely a forward pointer for "which ledger row did this
 * line make."
 *
 * `quantity` is `decimal(12,3)`, the same precision `stock_movements.quantity` uses — both
 * describe the same physical count, and a mismatched precision between the two would make a
 * reconciliation report lie. Whether a fractional value is allowed for a given size is decided
 * once, by `RecordStockMovement::guardWholeQuantity()`, and applies here automatically because
 * every line posts through it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_arrival_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stock_arrival_id')->constrained('stock_arrivals')->cascadeOnDelete();

            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();

            $table->decimal('quantity', 12, 3);

            $table->foreignId('stock_movement_id')->constrained('stock_movements');

            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['stock_arrival_id', 'created_at']);
        });

        // A line of nothing is not a delivery — the same rule stock_movements.quantity holds.
        DB::statement(
            'ALTER TABLE stock_arrival_items
             ADD CONSTRAINT stock_arrival_items_quantity_is_positive CHECK (quantity > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_arrival_items');
    }
};
