<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bag type from the catalogue — كيس شحن, كيس يد خارجية, كيس ورقي 3D …
 *
 * Two axes decide how a product is sold, and they are independent:
 *   pricing_unit  — is it sold by the piece, or by the kilo?
 *   pricing_mode  — does it have listed prices, or is every order quoted by hand?
 *
 * Keeping them apart means a per-kilo product can later become quote-only, or a quote-only
 * product can gain a price list, without either concept having to know about the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // Stable machine name used by the app and by seeders; the display name is Arabic
            // and may be reworded at any time, so it must not be the identifier.
            $table->string('slug', 80)->unique();

            $table->string('name');
            $table->text('description')->nullable();

            // Selling points shown under the price table in the catalogue.
            $table->json('features')->nullable();

            $table->string('category', 20)->index();      // مطبوعة/سادة — dropped 2026-08-13
            $table->string('pricing_unit', 20);           // PricingUnit
            $table->string('pricing_mode', 20)->index();  // PricingMode

            // Decimal, not integer: a per-kilo product's minimum is a weight, and weights are
            // fractional. Validation enforces whole numbers for per-piece products.
            $table->decimal('min_order_quantity', 12, 3)->default(1);

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
