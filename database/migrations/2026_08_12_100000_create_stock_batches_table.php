<?php

use App\Domain\Inventory\Actions\ApplyStockChange;
use App\Domain\Inventory\Actions\ConsumeStockBatchesFifo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One cost layer of one size in one warehouse.
 *
 * `warehouse_stocks.quantity` says how much of a size is on a shelf; this table says what each
 * unit of it actually cost. `SUM(quantity_remaining)` for a given `(warehouse_id,
 * product_variant_id)` must always equal that shelf's `warehouse_stocks.quantity` — the same
 * balance-equals-ledger invariant `stock_movements` holds for quantity alone, extended to cost.
 * {@see ApplyStockChange} is the only code that writes either side,
 * under the same row lock, in the same transaction — so the two can never drift apart.
 *
 * `received_at` is the FIFO ordering key, deliberately not `created_at`: a batch relocated by an
 * internal transfer keeps the `received_at` of the stock it actually is, so goods do not get
 * younger by moving shelves. {@see ConsumeStockBatchesFifo} always
 * draws down the oldest `received_at` first.
 *
 * `stock_arrival_item_id` is reserved for tracing a batch back to the costed arrival line that
 * created it, but is not populated yet — `RecordStockArrival` creates the `StockArrivalItem` row
 * *after* the movement (and therefore the batch) it produced, so the id does not exist yet at
 * batch-creation time. Wiring that traceability through is a follow-up that reorders
 * `RecordStockArrival`, not part of this change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();

            $table->string('source_type', 20);   // StockBatchSourceType

            $table->foreignId('stock_arrival_item_id')->nullable()
                ->constrained('stock_arrival_items')->nullOnDelete();

            // Three places, matching every other unit cost in this schema (purchase_order_items,
            // stock_arrival_items) — a per-kilo rate needs finer than cents.
            $table->decimal('unit_cost', 12, 3);

            $table->decimal('quantity_received', 12, 3);
            $table->decimal('quantity_remaining', 12, 3);

            // Snapshot, same convention as warehouse_stocks.unit and purchase_order_items.unit.
            $table->string('unit', 20);

            $table->timestamp('received_at');

            $table->timestamps();
            $table->softDeletes()->index();

            // FIFO consumption always asks "which of this size, in this warehouse, still has
            // something left, oldest first" — this is that query's index.
            $table->index(['warehouse_id', 'product_variant_id', 'received_at']);
        });

        DB::statement(
            'ALTER TABLE stock_batches
             ADD CONSTRAINT stock_batches_unit_cost_not_negative CHECK (unit_cost >= 0)'
        );

        DB::statement(
            'ALTER TABLE stock_batches
             ADD CONSTRAINT stock_batches_quantity_received_positive CHECK (quantity_received > 0)'
        );

        DB::statement(
            'ALTER TABLE stock_batches
             ADD CONSTRAINT stock_batches_quantity_remaining_not_negative CHECK (quantity_remaining >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batches');
    }
};
