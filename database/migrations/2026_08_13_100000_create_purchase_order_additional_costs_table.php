<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order-level costs that aren't tied to any single line — delivery, unloading, customs. A
 * dynamic list rather than fixed columns, because which of these apply (and how many) differs
 * order to order; see {@see PurchaseOrder::additionalCosts()}.
 *
 * Synced wholesale on `PUT` the same way `purchase_order_items` already is — an entry carrying
 * an `id` updates that cost, one without creates a new one, and any existing entry missing from
 * the set is removed. See `UpdatePurchaseOrder::syncAdditionalCosts()`.
 *
 * `amount` is `>= 0`, not `> 0`: a listed cost that turned out to be waived is a real line worth
 * keeping on the document, the same reasoning `purchase_order_items.unit_cost` already applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_additional_costs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();

            $table->string('name');
            $table->decimal('amount', 14, 2);

            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['purchase_order_id', 'created_at']);
        });

        DB::statement(
            'ALTER TABLE purchase_order_additional_costs
             ADD CONSTRAINT purchase_order_additional_costs_amount_not_negative CHECK (amount >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_additional_costs');
    }
};
