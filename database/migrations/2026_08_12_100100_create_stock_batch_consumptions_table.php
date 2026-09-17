<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One FIFO draw against one batch — the ledger that makes `stock_batches.quantity_remaining`
 * explainable, the same role `stock_movements` plays for `warehouse_stocks.quantity`.
 *
 * **Append-only**, like `stock_movements` and `order_payments`. A `stock_movements` row that
 * decreases a balance can draw from several batches at once — the oldest is not always enough —
 * so one movement produces one row here per batch it touched.
 *
 * `unit_cost`/`total_cost` are snapshotted from the batch at the moment of consumption rather
 * than joined at read time, so a later change to how a batch is priced (there is none today, but
 * nothing prevents one) can never rewrite a cost this row already reported.
 *
 * The soft-delete column is here because every table in this schema carries one and a test
 * enforces that — not because anything removes a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_batch_consumptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stock_batch_id')->constrained('stock_batches')->cascadeOnDelete();
            $table->foreignId('stock_movement_id')->constrained('stock_movements')->cascadeOnDelete();

            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 12, 3);
            $table->decimal('total_cost', 14, 2);

            $table->timestamps();
            $table->softDeletes()->index();

            // Reversing a movement (crediting its batches back) starts from "every consumption
            // this movement produced" — see the plan for order-cancellation reversal.
            $table->index('stock_movement_id');
        });

        DB::statement(
            'ALTER TABLE stock_batch_consumptions
             ADD CONSTRAINT stock_batch_consumptions_quantity_positive CHECK (quantity > 0)'
        );

        DB::statement(
            'ALTER TABLE stock_batch_consumptions
             ADD CONSTRAINT stock_batch_consumptions_unit_cost_not_negative CHECK (unit_cost >= 0)'
        );

        DB::statement(
            'ALTER TABLE stock_batch_consumptions
             ADD CONSTRAINT stock_batch_consumptions_total_cost_not_negative CHECK (total_cost >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batch_consumptions');
    }
};
