<?php

use App\Domain\Inventory\Exceptions\VariantHasNoStockItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which shelf this size draws from.
 *
 * The one link between the catalogue and the warehouse. Many variants point at one stock item —
 * كيس شحن سادة 25*35 and كيس شحن مطبوع 25*35 are two rows here and one pile out there — which is
 * the entire reason `stock_items` exists.
 *
 * **Nullable on purpose.** A quote-only product that is never stocked has no shelf, and a
 * NOT NULL column would only teach whoever creates it to point at some unrelated row to get past
 * the form. Every path that actually moves stock refuses a variant without one, loudly and in
 * Arabic — see {@see VariantHasNoStockItem}, which guards both
 * fulfilment and arrival.
 *
 * `nullOnDelete`, not `cascadeOnDelete`: deleting a stock item must never take a product's size
 * with it. `DeleteStockItem` already refuses while any warehouse still holds the item, so the
 * only rows this can null out belong to shelves that were empty anyway.
 *
 * **Deliberately not constrained to match the variant's own `width_cm`/`height_cm`.** A 25*35 bag
 * can legitimately be cut from a wider sheet, and a rule that is right most of the time gets
 * worked around by lying about dimensions. Both sizes are rendered side by side so a mismatch is
 * visible instead of silently prevented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignId('stock_item_id')->nullable()->after('product_id')
                ->constrained('stock_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_item_id');
        });
    }
};
