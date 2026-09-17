<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line of an order: this many of that size of that bag, at the price agreed on the day.
 *
 * **Everything a person reads on an old order is copied here, not joined to.** `product_name`,
 * `variant_label` and `pricing_unit` sit beside the foreign keys because a product gets renamed,
 * a size gets relabelled, and a price tier moves the moment paper costs move — and an invoice
 * that changes after it was issued is not an invoice. The keys remain for filtering ("how much
 * كيس شحن did we print this year") and for opening the live catalogue entry.
 *
 * `unit_price` comes from `CatalogService::quote()` and from nowhere else, so the number a
 * customer was shown and the number written here are produced by the same code.
 *
 * Three decimals on quantity and unit price, two on the line total: a per-kilo product is
 * ordered in fractions and its rate is quoted per kilo, but what lands on the invoice is money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            // No cascade: a product is deactivated, never deleted.
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('product_variant_id')->constrained('product_variants');

            // The snapshot — see the class docblock.
            $table->string('product_name');
            $table->string('variant_label', 60);
            $table->string('pricing_unit', 20);

            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 3);
            $table->decimal('line_total', 14, 2);

            $table->text('notes')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['order_id', 'sort_order']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
