<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The shape rule: a reason belongs to a decreasing adjustment and to nothing else.
 *
 * Three legal shapes, and the CHECK is written as their disjunction so each says which case it
 * permits:
 *
 * - not an adjustment at all → no reason (an arrival, a transfer, a fulfilment and a scrap loss
 *   all explain themselves by their type);
 * - an adjustment that *added* stock → no reason (finding more than the book said is not a loss,
 *   and «هالك» on a row that raised a balance would be a contradiction the reader has to unpick);
 * - an adjustment that removed stock → a reason, always.
 *
 * Last of the three migrations on purpose. Applied beside the column it would have failed on
 * every database that has ever recorded an adjustment, because those rows are NULL here until
 * the backfill before it has run.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE stock_movements
            ADD CONSTRAINT stock_movements_adjustment_reason_shape
            CHECK (
                (movement_type <> 'adjustment' AND adjustment_reason IS NULL)
                OR (movement_type = 'adjustment' AND from_warehouse_id IS NULL AND adjustment_reason IS NULL)
                OR (movement_type = 'adjustment' AND from_warehouse_id IS NOT NULL AND adjustment_reason IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_adjustment_reason_shape');
    }
};
