<?php

use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Enums\StockAdjustmentReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every decrease recorded before the vocabulary existed becomes «فرق جرد».
 *
 * It is the only honest answer. The reason those rows were recorded is in their free-text
 * `notes` and nowhere else, and reading Arabic prose to decide between «هالك» and «عجز» is a
 * guess this migration would be writing into a column people are about to trust. «فرق جرد» says
 * precisely what is known: the shelf and the book disagreed and somebody corrected it.
 *
 * The notes are untouched and still say whatever they said, so nothing is lost — only nothing
 * is invented either.
 *
 * Its own migration, per RULES §8: a schema migration does not carry data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('stock_movements')
            ->where('movement_type', MovementType::Adjustment->value)
            ->whereNotNull('from_warehouse_id')
            ->whereNull('adjustment_reason')
            ->update(['adjustment_reason' => StockAdjustmentReason::CountCorrection->value]);
    }

    public function down(): void
    {
        // Nothing to undo that would not be another guess: which of these rows this migration
        // filled, and which arrived already carrying `count_correction`, is not recorded.
    }
};
