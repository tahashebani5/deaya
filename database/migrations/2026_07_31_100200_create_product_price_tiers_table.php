<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quantity break: "from this quantity upward, the unit price is this".
 *
 * The catalogue currently prints three columns — تحت 300 / فوق 300 / فوق 1000 — but they are
 * stored as rows, not as three columns on the variant. Rows cost nothing extra and mean a
 * product that needs a fourth break, or thresholds of its own, is a data change rather than a
 * migration plus a rewrite of every pricing query.
 *
 * `min_quantity` is an inclusive floor: the price that applies is the highest tier whose
 * min_quantity does not exceed the ordered quantity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_tiers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();

            // Decimal because a per-kilo threshold is a weight.
            $table->decimal('min_quantity', 12, 3);

            // Price per piece, or per kilo, depending on the product's pricing_unit.
            // decimal, never a float: money must not drift when it is compared or summed.
            // Scale 3 carries the dinar's dirham subdivision even though the catalogue prints two.
            $table->decimal('unit_price', 12, 3);

            $table->timestamps();

            // A variant cannot have two prices starting at the same quantity — that would make
            // the applicable price depend on row order.
            $table->unique(['product_variant_id', 'min_quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_tiers');
    }
};
