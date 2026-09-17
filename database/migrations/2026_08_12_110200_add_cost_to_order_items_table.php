<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What one line actually cost to produce — the COGS side of the P&L, matching `line_total` on
 * the revenue side.
 *
 * All four nullable: there is no correct value to backfill for an order placed before this
 * feature existed, and none for a line that has not yet reached printing. `material_cost` is a
 * permanent snapshot force-filled once by `DeductOrderStock`, the same treatment
 * `warehouse_quantity` already gets — never recomputed. `labor_cost`/`overhead_cost` are cached
 * sums recomputed whenever a relevant `production_cost_entries` row changes, the same relationship
 * `orders.paid_amount` has with `order_payments`. `cogs` is their sum, force-filled by the one
 * action that combines all three so nothing else has to restate the addition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('material_cost', 14, 2)->nullable()->after('line_total');
            $table->decimal('labor_cost', 14, 2)->nullable()->after('material_cost');
            $table->decimal('overhead_cost', 14, 2)->nullable()->after('labor_cost');
            $table->decimal('cogs', 14, 2)->nullable()->after('overhead_cost');
        });

        foreach (['material_cost', 'labor_cost', 'overhead_cost', 'cogs'] as $column) {
            DB::statement(
                "ALTER TABLE order_items ADD CONSTRAINT order_items_{$column}_not_negative CHECK ({$column} >= 0)"
            );
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['material_cost', 'labor_cost', 'overhead_cost', 'cogs']);
        });
    }
};
