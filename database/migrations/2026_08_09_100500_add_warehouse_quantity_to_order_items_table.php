<?php

use App\Domain\Order\Actions\DeductOrderStock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much this line actually takes out of the warehouse, typed by whoever fulfils the order.
 *
 * **Manual, not derived — and not a multiplier.** A batch of bags is weighed together on a
 * scale, not counted piece by piece and multiplied by a per-piece factor, so this is the total
 * for the whole line in the warehouse's own unit, entered directly. Null is the common case —
 * sales unit and warehouse unit agree, and {@see DeductOrderStock} deducts the ordered quantity
 * unchanged. When it is set, it is a permanent snapshot of what the scale read that day, the
 * same reasoning `pricing_unit`/`variant_label` are copied rather than joined for on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('warehouse_quantity', 12, 3)->nullable()->after('pricing_unit');
        });

        DB::statement(
            'ALTER TABLE order_items
             ADD CONSTRAINT order_items_warehouse_quantity_is_positive CHECK (warehouse_quantity > 0)'
        );
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('warehouse_quantity');
        });
    }
};
