<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * التصنيف — where a product stands in the catalogue: أكياس, علب وكراتين التغليف, ستيكرات.
 *
 * **Not the `category` column already on `products`.** That one is مطبوعة/سادة — how a bag is
 * made and billed — and it was only ever *called* a category. It is «النوع» now, and this table
 * takes the word back. See PRODUCT-CATEGORIES.md.
 *
 * **A table, not an enum**, for the reason `business_fields` is one: the list is the business's
 * to shape, it grows the first week somebody sells a thing nobody planned for, and an enum would
 * make each addition a deployment.
 *
 * `is_active` rather than deletion for the ordinary case — a category we stop selling is still
 * the truth about the products under it. Deleting is kept for the row that should never have
 * existed, and is refused outright once any product points at one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();

            // What the catalogue prints as a heading. The unique index is added below rather
            // than here — see the comment on it.
            $table->string('name', 100);

            // The sentence under the heading on the catalogue page: «تشكيلة أكياس مخصصة للتغليف
            // والشحن…». Nullable, because a category is useful the moment it has a name.
            $table->string('description', 500)->nullable();

            // Whether it is still offered when a product is recorded. Products already on it
            // are unaffected: this hides the row from the picker, it does not retract it.
            $table->boolean('is_active')->default(true);

            // The catalogue's order. Alphabetical order of Arabic names depends on the
            // database's collation and would bury the category most products are in.
            $table->integer('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();
        });

        // Partial, as every unique index in this schema is: `deleted_at` says a row is gone
        // while a plain unique index still counts it, so deleting «أكياس» and adding it back
        // would fail against a row the API insists does not exist.
        DB::statement(
            'CREATE UNIQUE INDEX product_categories_name_unique
             ON product_categories (name) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
