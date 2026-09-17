<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One zero-cost batch per (warehouse, size) that already held stock the day batch costing went
 * live — there is no purchase history to cost this quantity from, the same honest gap
 * `stock_arrival_items.unit_cost` already leaves null for a plain unplanned arrival.
 *
 * `received_at` is a fixed epoch, deliberately not `created_at` or "now": it must sort before
 * every batch created after this migration runs, so FIFO consumption burns off this unknown-cost
 * stock first rather than leaving it stranded behind newer, correctly-costed layers.
 *
 * Forward-only, like every migration here — there is nothing to revert to. The `down()` on the
 * table-creation migrations already removes the tables these rows would live in.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO stock_batches (
                warehouse_id, product_variant_id, source_type, unit_cost,
                quantity_received, quantity_remaining, unit, received_at, created_at, updated_at
            )
            SELECT
                warehouse_id, product_variant_id, 'opening_balance', 0,
                quantity, quantity, unit, TIMESTAMP '1970-01-01 00:00:00', NOW(), NOW()
            FROM warehouse_stocks
            WHERE deleted_at IS NULL AND quantity > 0
        SQL);
    }

    public function down(): void
    {
        DB::table('stock_batches')->where('source_type', 'opening_balance')->delete();
    }
};
