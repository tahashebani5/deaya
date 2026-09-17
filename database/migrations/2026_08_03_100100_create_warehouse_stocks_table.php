<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much of one size sits in one warehouse.
 *
 * **This table is a running balance, not an input.** Every number in `quantity` was put there by
 * a row in `stock_movements`, inside the same transaction and under a row lock — there is no
 * endpoint that sets it. The ledger is the truth; this is the answer to "how many, right now",
 * kept here so the common question does not cost a sum over every movement ever recorded.
 *
 * The two are allowed to be checked against each other, and a test does exactly that.
 *
 * `low_stock_threshold` is the one column on this table a human edits, because it is a setting
 * rather than a fact: "tell me when this drops below 500".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();

            // Decimal, matching product_price_tiers.min_quantity: a per-kilo product's stock is
            // a weight, and weights are fractional. Never a float — these numbers are summed,
            // and a shelf count that drifts by 0.0000001 is worse than no count.
            $table->decimal('quantity', 12, 3)->default(0);

            // Null means nobody has asked to be warned about this line yet, which is a different
            // thing from 0. Zero as the default would mark every empty shelf "low" forever and
            // the warning would stop being read.
            $table->decimal('low_stock_threshold', 12, 3)->nullable();

            $table->timestamps();
            $table->softDeletes()->index();

            // Serves the per-warehouse listing, which is the only way this table is read.
            $table->index(['warehouse_id', 'product_variant_id']);
        });

        // One balance row per size per warehouse. This is not bookkeeping tidiness: the whole
        // lock-and-increment in ApplyStockChange assumes there is exactly one row to lock, and
        // a duplicate would let two movements update different rows and lose one of them.
        DB::statement(
            'CREATE UNIQUE INDEX warehouse_stocks_one_row_per_variant_per_warehouse
             ON warehouse_stocks (warehouse_id, product_variant_id) WHERE deleted_at IS NULL'
        );

        // A balance can be zero but never negative. Validation refuses it with a readable 422;
        // this is what holds if a future caller ever writes the column without going through
        // the action — the failure a constraint gives is loud, and a negative shelf is silent.
        DB::statement(
            'ALTER TABLE warehouse_stocks
             ADD CONSTRAINT warehouse_stocks_quantity_not_negative CHECK (quantity >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stocks');
    }
};
