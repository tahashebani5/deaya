<?php

use App\Domain\Inventory\Actions\ApplyStockChange;
use App\Domain\Inventory\Actions\CreditBackStockBatches;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which movement a reversal undid — a fact the ledger carried in memory and never wrote down.
 *
 * `StockMovementData::$reversedMovementId` has always existed and has always been consumed by
 * {@see ApplyStockChange::creditBack()} to find the layers to
 * credit. It was never persisted, so afterwards **nothing in the database said a movement had
 * been reversed**: no consumption row is flagged, no negative row is written, and
 * `ReverseOrderStockDeduction` does not even clear `order_items.fulfillment_stock_movement_id`.
 * A cancelled sale therefore went on counting in `SUM(stock_batch_consumptions)` forever, which
 * is exactly the sum any per-deal or per-period cost report has to take.
 *
 * The partial UNIQUE is the second half, and the more valuable one: crediting the same movement
 * back twice stops being a silent doubling of the shelf and becomes a database error.
 * {@see CreditBackStockBatches} has no guard of its own — reversal is idempotent today only
 * because the status machine happens to call it once.
 *
 * Backfill is deliberately absent. Matching historic reversals to their fulfilments by
 * timestamp and quantity would be a guess written into a column people will trust, and the rows
 * that need it do not exist yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('reverses_movement_id')
                ->nullable()
                ->after('reference_id')
                ->constrained('stock_movements')
                ->nullOnDelete();
        });

        // Partial, like every unique index in this schema: a soft-deleted movement must not
        // reserve the reversal slot of the one it undid.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX stock_movements_reverses_movement_id_unique
            ON stock_movements (reverses_movement_id)
            WHERE reverses_movement_id IS NOT NULL AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stock_movements_reverses_movement_id_unique');

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reverses_movement_id');
        });
    }
};
