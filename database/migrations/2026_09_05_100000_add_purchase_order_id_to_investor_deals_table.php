<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The purchase order a deal was born from.
 *
 * The deal never stopped being anchored to a purchase order — `investor_deal_supplies` has said
 * «this order's line is financed by this deal» since the feature shipped. What it could not say
 * is that a deal *is* one order's paperwork, so the shelves, the claim and the money were three
 * things typed by hand into a form that already existed as a document.
 *
 * Nullable, because the older path stands: a deal assembled by hand out of several orders, or
 * out of none yet, is still a deal. **Unique where set**, because the funding screen is «this
 * order, these partners, this money» — a second deal over the same order would give the receipt
 * two answers to a question that takes one, and `dealForSupply` returns the first row it finds.
 *
 * `nullOnDelete` rather than cascade: nothing hard-deletes a purchase order today, and if one
 * ever were, the deal's own money and layers must outlive it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investor_deals', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')
                ->nullable()
                ->after('product_id')
                ->constrained('purchase_orders')
                ->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX investor_deals_purchase_order_unique
            ON investor_deals (purchase_order_id)
            WHERE purchase_order_id IS NOT NULL AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS investor_deals_purchase_order_unique');

        Schema::table('investor_deals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });
    }
};
