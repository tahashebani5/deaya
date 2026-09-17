<?php

declare(strict_types=1);

use App\Domain\Inventory\Actions\RevalueStockBatch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every time somebody changed what a quantity of stock is carried at, and why.
 *
 * **A revaluation is the one thing in this domain that moves money without moving stock.** Every
 * other event has a `stock_movements` row explaining it: goods arrived, went out, moved shelves,
 * were counted again. A correction to a cost has no such row — the shelf is untouched — so
 * without this table the only trace would be a column quietly holding a different number.
 *
 * **The audit trail is not a substitute, and that is the whole argument for a second table.** It
 * records that `unit_cost` went from 0.000 to 3.500; it has nowhere to put «فاتورة المورد وصلت
 * بسعر مختلف», which is the part somebody will actually be looking for. And one revaluation can
 * touch two rows — repricing part of a layer splits it — so «what happened here» is a fact about
 * an *event*, not about either row it left behind.
 *
 * It is also the only place «كم رفعنا أو خفّضنا قيمة المخزون هذا الربع؟» could ever be answered
 * from. Nothing reports on it yet, deliberately: the P&L recognises revenue and cost of goods
 * sold and has no inventory-valuation line to hang this on. The record is kept now so that the
 * report is possible later, rather than the question being unanswerable for the months before
 * somebody asks it.
 *
 * `quantity` is what was repriced, not what the layer holds — on a partial revaluation the two
 * differ, and it is the repriced quantity that says how much value moved. Both costs are stored
 * rather than a delta, for the reason every money column in this schema is stored rather than
 * derived: a delta cannot be read back as «من كم إلى كم».
 *
 * Written only by {@see RevalueStockBatch}, inside the same
 * transaction and under the same balance lock as the batch rows it explains.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_batch_revaluations', function (Blueprint $table) {
            $table->id();

            // The row that ended up carrying the new cost. On a split that is the original
            // layer, which keeps its place in the FIFO queue — see RevalueStockBatch.
            $table->foreignId('stock_batch_id')->constrained('stock_batches')->cascadeOnDelete();

            $table->decimal('quantity', 12, 3);

            // Three places, like every other unit cost in this schema.
            $table->decimal('old_unit_cost', 12, 3);
            $table->decimal('new_unit_cost', 12, 3);

            // Required, never nullable: a change to the books with no physical event behind it
            // must not be able to exist unexplained. The same demand `RecordAdjustmentRequest`
            // already makes of a stocktake correction, for the same reason.
            $table->text('reason');

            // Who decided. From the authenticated user at the boundary, never from a payload —
            // the treatment `stock_movements.employee_id` already gets.
            $table->foreignId('user_id')->constrained('users');

            $table->timestamps();
            $table->softDeletes()->index();

            // "What has been done to this layer" is the only way this table is read today.
            $table->index(['stock_batch_id', 'created_at']);
        });

        DB::statement(
            'ALTER TABLE stock_batch_revaluations
             ADD CONSTRAINT stock_batch_revaluations_quantity_positive CHECK (quantity > 0)'
        );

        DB::statement(
            'ALTER TABLE stock_batch_revaluations
             ADD CONSTRAINT stock_batch_revaluations_old_cost_not_negative CHECK (old_unit_cost >= 0)'
        );

        DB::statement(
            'ALTER TABLE stock_batch_revaluations
             ADD CONSTRAINT stock_batch_revaluations_new_cost_not_negative CHECK (new_unit_cost >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batch_revaluations');
    }
};
