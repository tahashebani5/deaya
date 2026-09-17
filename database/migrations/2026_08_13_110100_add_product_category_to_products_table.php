<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which catalogue category a product sits in.
 *
 * **Nullable here, required in the request — and the two do not disagree.** Every product
 * recorded before this migration exists already, and making the column `NOT NULL` would have
 * meant inventing a «غير مصنّف» category to park them in, which then lives in the catalogue
 * forever. `StoreProductRequest` and `UpdateProductRequest` both demand the field, so nothing
 * saved from today lacks one, and `ProductCategorySeeder` fills in what was there before.
 *
 * `nullOnDelete` is a floor rather than a path anybody takes: deleting a category any product
 * points at is refused outright — see DeleteProductCategory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('product_category_id')
                ->nullable()
                ->after('category')
                ->constrained('product_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_category_id');
        });
    }
};
