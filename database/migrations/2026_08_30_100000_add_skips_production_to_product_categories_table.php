<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether goods under a heading reach the shelf without the press.
 *
 * **The lever the order flow is decided by**, and it is one column rather than a list in code
 * because the business has to be able to answer «أي تصنيف لا يُطبع؟» without a deployment. That
 * is the same reasoning that made `product_categories` a table and not an enum in the first
 * place — see the migration that created it.
 *
 * **On the category, not on the product.** A product's answer is "whatever its heading says", and
 * a column here is one row edited once against a column there edited per product forever. It is
 * also the honest place for it: «سادة» *is* the statement that a thing is not printed —
 * PRODUCT-CATEGORIES.md records that the مطبوعة/سادة split «لم يدخل في أي حساب» when it became
 * two headings. This is the calculation it finally enters.
 *
 * Defaults to false, so deploying this changes nothing about any order. The backfill below is the
 * only row it turns on, and it is a fact about this data rather than a rule — the same register
 * `ProductCategorySeeder` uses for its own backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            // Beside `is_active`, because they are read together: one says whether the heading is
            // still offered, the other what it means for the orders that use it.
            $table->boolean('skips_production')->default(false)->after('is_active');
        });

        // «سادة» — «منتجات بلا طباعة، تُباع غالباً بالوزن», in the seeder's own words. It is the
        // heading this feature was asked for, so an install that already has it gets the
        // behaviour without somebody having to go and find the switch.
        //
        // Matched by name and only where nobody has decided otherwise yet, exactly as the seeder
        // matches: a heading somebody has since renamed is theirs, not ours.
        DB::table('product_categories')
            ->where('name', 'سادة')
            ->whereNull('deleted_at')
            ->update(['skips_production' => true]);
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn('skips_production');
        });
    }
};
