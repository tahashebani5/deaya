<?php

use App\Domain\Inventory\Enums\StockAdjustmentReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «هالك» و«عجز» — a vocabulary for a decrease that had none.
 *
 * The whole of it was free text in `notes`, so «كم هالك هذا الشهر؟» could not be asked of the
 * ledger at all. See {@see StockAdjustmentReason} for why this is a
 * reason column rather than a fifth `MovementType`.
 *
 * Nullable, and this migration adds nothing else: every decreasing adjustment already on the
 * books has no answer here, and RULES §8 keeps data out of a schema migration. The backfill and
 * the shape CHECK are the two migrations after this one, in that order — adding the constraint
 * beside the column would fail on any database that has ever recorded an adjustment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('adjustment_reason', 20)->nullable()->after('movement_type');

            // «أرِني كل الهالك هذا الشهر» is the query this column exists for, and it is always
            // asked with a date beside it.
            $table->index(['adjustment_reason', 'created_at'], 'stock_movements_reason_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_reason_created_index');
            $table->dropColumn('adjustment_reason');
        });
    }
};
