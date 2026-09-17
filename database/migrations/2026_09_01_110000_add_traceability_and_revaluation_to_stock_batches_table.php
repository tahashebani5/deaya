<?php

declare(strict_types=1);

use App\Domain\Inventory\Actions\ApplyStockChange;
use App\Domain\Inventory\Actions\RecordStockMovement;
use App\Domain\Inventory\Actions\RevalueStockBatch;
use App\Domain\Vendor\Actions\RecordStockArrival;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a cost layer came from, and what has been done to it since.
 *
 * **`stock_movement_id` closes a gap this table has had since it was created.** A batch recorded
 * what stock cost and nothing at all about the event that brought it in: `stock_arrival_item_id`
 * was reserved for that and is still never populated, because {@see RecordStockArrival}
 * writes the arrival line *after* the movement that opens the batch. The movement's id, by
 * contrast, is already in hand at exactly the right moment — {@see RecordStockMovement} passes it
 * to `decrease()` for the consumption rows and simply never passed it to `increase()`.
 *
 * One column, and every layer becomes answerable: which movement, therefore which employee,
 * which document, and — through `stock_movements.reference_id` — which purchase order. That is
 * what lets a screen say «من توريد رقم ١٢» instead of listing five anonymous layers, and it is
 * what the revaluation screen warns from before somebody edits a price that came off an invoice.
 *
 * **`split_from_batch_id` is the lineage of a partial revaluation.** Repricing part of a layer
 * splits it — see {@see RevalueStockBatch} — and without this the
 * second row would look like an unexplained duplicate of the first.
 *
 * **`revalued_at` is a convenience and nothing more.** Who changed a cost and why is in the
 * audit trail and in `stock_batch_revaluations`; this exists so a list can mark a layer
 * «سُعِّرت يدوياً» without joining either.
 *
 * All three nullable and unbackfilled. A batch written before today has no movement to point at
 * that we could prove — matching them by timestamp would be a guess presented as a fact — so it
 * reads «غير معروف», which is the honest answer for it. See {@see ApplyStockChange}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_batches', function (Blueprint $table) {
            $table->foreignId('stock_movement_id')->nullable()
                ->after('stock_arrival_item_id')
                ->constrained('stock_movements')->nullOnDelete();

            $table->foreignId('split_from_batch_id')->nullable()
                ->after('stock_movement_id')
                ->constrained('stock_batches')->nullOnDelete();

            $table->timestamp('revalued_at')->nullable()->after('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('stock_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_movement_id');
            $table->dropConstrainedForeignId('split_from_batch_id');
            $table->dropColumn('revalued_at');
        });
    }
};
