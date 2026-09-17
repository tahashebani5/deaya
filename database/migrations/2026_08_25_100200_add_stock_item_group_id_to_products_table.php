<?php

use App\Domain\Inventory\Actions\ResolveStockItemForVariant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this product is made of.
 *
 * The whole point of the group: say it once here, and every one of the product's sizes finds its
 * own shelf. {@see ResolveStockItemForVariant} matches each variant to the group's item of the
 * same size on save — creating it when the group has not reached that size yet — so nobody picks
 * a shelf size by size ever again.
 *
 * **Nullable, and a default rather than a rule.** A product priced on request has no material
 * agreed yet; one whose sizes are cut from three different films cannot name a single group. Both
 * still work exactly as before: `product_variants.stock_item_id` remains the authority, and an
 * explicit id in the payload always beats whatever the group would have resolved to. The group
 * fills the column in; it never overrules it.
 *
 * `nullOnDelete` — deleting a group must not take products with it. `DeleteStockItemGroup`
 * refuses while anything still points here anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('stock_item_group_id')->nullable()->after('product_category_id')
                ->constrained('stock_item_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_item_group_id');
        });
    }
};
