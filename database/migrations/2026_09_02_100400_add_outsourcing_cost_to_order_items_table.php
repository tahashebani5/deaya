<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a وسيط line costs us, copied onto the order the day it is taken.
 *
 * **`unit_cost` is the snapshot, and it is the whole of «تغيير التكلفة لاحقاً لا يغيّر الطلبات
 * السابقة».** It is force-filled from `product_variants.cost_price` by `AddOrderItem`, exactly as
 * `unit_price` is force-filled from the price tiers beside it, and nothing ever reads the
 * catalogue for this line again. Raising a vendor's price next month moves the next order and
 * leaves every earlier one saying what it actually cost.
 *
 * **`outsourcing_cost` is the recognised total**, written by `ApplyOutsourcingCosts` when the
 * order first reaches «جاهزة» — the same moment `ApplyManufacturingRates` costs a printed one.
 * Snapshot at intake, recognition at ready: an order cancelled on the vendor's bench never
 * incurred a cost, and a line delivered short is costed on what actually arrived.
 *
 * It becomes the fourth component of `order_items.cogs` and so of `orders.total_cogs`. There is
 * no fifth column for it on the order: a total that is the sum of its lines already has one.
 *
 * Both nullable, and no backfill: no order taken before this was ever sent to a vendor.
 * `unit_cost` carries three decimals like the price it sits beside; `outsourcing_cost` carries
 * two like every other money column on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 14, 3)->nullable()->after('unit_price');
            $table->decimal('outsourcing_cost', 14, 2)->nullable()->after('material_cost');
        });

        foreach (['unit_cost', 'outsourcing_cost'] as $column) {
            DB::statement(
                "ALTER TABLE order_items ADD CONSTRAINT order_items_{$column}_not_negative CHECK ({$column} >= 0)"
            );
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'outsourcing_cost']);
        });
    }
};
