<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inverts what a purchase order line is priced by, and adds the columns needed to distribute the
 * order's additional costs across its lines.
 *
 * `unit_cost` used to be the input, with `total_cost` derived from it. That is now backwards:
 * `base_total_cost` is what whoever raised the order was actually quoted for the line, and
 * `base_unit_cost` is derived from it — see {@see AllocatePurchaseOrderAdditionalCosts}. Both
 * old columns are dropped rather than kept alongside the new ones: their meaning inverts, so
 * keeping `unit_cost` around would silently disagree with `base_unit_cost` on every row.
 *
 * `allocated_additional_cost` is this line's share of {@see PurchaseOrder}'s additional costs
 * (delivery, customs, unloading, ...), and `final_unit_cost`/`final_total_cost` are the base
 * figures plus that share — the landed cost per unit, which is what
 * {@see ReceivePurchaseOrder} now carries onto a stock arrival instead of the base figure.
 *
 * All five nullable, the same reasoning the columns they replace already carried: a line written
 * before this feature (or before cost tracking existed at all) has nothing to backfill these
 * with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('base_total_cost', 14, 2)->nullable()->after('quantity_received');
            $table->decimal('base_unit_cost', 12, 3)->nullable()->after('base_total_cost');
            $table->decimal('allocated_additional_cost', 14, 2)->nullable()->after('base_unit_cost');
            $table->decimal('final_unit_cost', 12, 3)->nullable()->after('allocated_additional_cost');
            $table->decimal('final_total_cost', 14, 2)->nullable()->after('final_unit_cost');
        });

        DB::statement('ALTER TABLE purchase_order_items DROP CONSTRAINT purchase_order_items_unit_cost_not_negative');
        DB::statement('ALTER TABLE purchase_order_items DROP CONSTRAINT purchase_order_items_total_cost_not_negative');

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'total_cost']);
        });

        DB::statement(
            'ALTER TABLE purchase_order_items
             ADD CONSTRAINT purchase_order_items_base_total_cost_not_negative CHECK (base_total_cost >= 0)'
        );
        DB::statement(
            'ALTER TABLE purchase_order_items
             ADD CONSTRAINT purchase_order_items_base_unit_cost_not_negative CHECK (base_unit_cost >= 0)'
        );
        DB::statement(
            'ALTER TABLE purchase_order_items
             ADD CONSTRAINT purchase_order_items_allocated_additional_cost_not_negative CHECK (allocated_additional_cost >= 0)'
        );
        DB::statement(
            'ALTER TABLE purchase_order_items
             ADD CONSTRAINT purchase_order_items_final_unit_cost_not_negative CHECK (final_unit_cost >= 0)'
        );
        DB::statement(
            'ALTER TABLE purchase_order_items
             ADD CONSTRAINT purchase_order_items_final_total_cost_not_negative CHECK (final_total_cost >= 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE purchase_order_items DROP CONSTRAINT purchase_order_items_base_total_cost_not_negative');
        DB::statement('ALTER TABLE purchase_order_items DROP CONSTRAINT purchase_order_items_base_unit_cost_not_negative');
        DB::statement('ALTER TABLE purchase_order_items DROP CONSTRAINT purchase_order_items_allocated_additional_cost_not_negative');
        DB::statement('ALTER TABLE purchase_order_items DROP CONSTRAINT purchase_order_items_final_unit_cost_not_negative');
        DB::statement('ALTER TABLE purchase_order_items DROP CONSTRAINT purchase_order_items_final_total_cost_not_negative');

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn([
                'base_total_cost', 'base_unit_cost', 'allocated_additional_cost',
                'final_unit_cost', 'final_total_cost',
            ]);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 3)->nullable()->after('quantity_received');
            $table->decimal('total_cost', 14, 2)->nullable()->after('unit_cost');
        });

        DB::statement(
            'ALTER TABLE purchase_order_items
             ADD CONSTRAINT purchase_order_items_unit_cost_not_negative CHECK (unit_cost >= 0)'
        );
        DB::statement(
            'ALTER TABLE purchase_order_items
             ADD CONSTRAINT purchase_order_items_total_cost_not_negative CHECK (total_cost >= 0)'
        );
    }
};
