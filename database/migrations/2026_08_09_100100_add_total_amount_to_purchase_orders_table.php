<?php

use App\Domain\Order\Actions\RecalculateOrderTotals;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What this order is planned to cost, summed from its own lines.
 *
 * Never typed and never trusted from a line-by-line sum a client could get wrong: written once by
 * {@see RecalculatePurchaseOrderTotal}, the same "one place decides the total" rule
 * {@see RecalculateOrderTotals} already holds for orders. Nullable
 * because a purchase order raised before this migration has no lines with a cost to sum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->decimal('total_amount', 14, 2)->nullable()->after('notes');
        });

        DB::statement(
            'ALTER TABLE purchase_orders
             ADD CONSTRAINT purchase_orders_total_amount_not_negative CHECK (total_amount >= 0)'
        );
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('total_amount');
        });
    }
};
