<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One buyable form of a product — almost always a size: 25*35, 35*40 …
 *
 * Every product has at least one, including the per-kilo plain bags, which carry a single
 * variant labelled سادة with no dimensions. Giving them a variant too means pricing has exactly
 * one shape to resolve instead of "price on the variant, unless there isn't one, then the
 * product" — the sort of branch that quietly grows a second bug in every feature that touches it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // What the customer sees: "25*35", or "سادة" for an unsized variant.
            $table->string('label', 60);

            // Null for variants that are not a size. Stored apart from the label so sizes can be
            // sorted and filtered numerically rather than as text.
            $table->unsignedSmallInteger('width_cm')->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // One product cannot list the same size twice.
            $table->unique(['product_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
