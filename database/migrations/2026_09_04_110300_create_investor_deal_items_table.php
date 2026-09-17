<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which shelves a deal funds — a child table, not a single column on the deal.
 *
 * A container carrying three sizes is **one deal with three rows**, not three deals sharing a
 * name. `stock_item_id` rather than a product or a size, because that is what stock is actually
 * keyed on since the August rekey, and it is what a `stock_batches` row will carry.
 *
 * The two expected figures are typed once, the day the deal is struck. There is no catalogue
 * number to read them from: a selling price lives per *size* as quantity tiers, and one shelf
 * stands behind several sizes of several products at several tiers, so there is no single price
 * to inherit. They drive the expected-profit line and nothing else — every realised figure comes
 * from what actually happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_deal_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('investor_deal_id')->constrained('investor_deals')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();

            $table->decimal('quantity_expected', 12, 3)->nullable();
            $table->decimal('expected_unit_cost', 12, 3)->nullable();
            $table->decimal('expected_unit_price', 12, 3)->nullable();

            $table->string('notes', 255)->nullable();

            $table->timestamps();
            $table->softDeletes()->index();

            $table->index('stock_item_id');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX investor_deal_items_one_row_per_item
            ON investor_deal_items (investor_deal_id, stock_item_id)
            WHERE deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE investor_deal_items
            ADD CONSTRAINT investor_deal_items_positive_figures
            CHECK (
                (quantity_expected IS NULL OR quantity_expected > 0)
                AND (expected_unit_cost IS NULL OR expected_unit_cost >= 0)
                AND (expected_unit_price IS NULL OR expected_unit_price >= 0)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_deal_items');
    }
};
