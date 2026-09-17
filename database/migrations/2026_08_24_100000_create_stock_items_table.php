<?php

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\Actions\AllocateStockItemIdentifier;
use App\Domain\Inventory\Actions\SetStockItemUnit;
use App\Domain\Inventory\InventoryService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A thing a warehouse holds — «كيس شحن 25*35», «كيس شحن 35*40».
 *
 * **This is what a shelf is now keyed on, and it is deliberately not a product.** A blank
 * shipping bag at one size is one pile in one place; the catalogue sells it twice, once as
 * كيس شحن سادة and once as كيس شحن مطبوع, and before this table those two products each had
 * their own private balance for a pile that only ever existed once. An order for 300 of one and
 * 400 of the other passed two separate checks against two shelves of 500 and then came up short
 * on the floor. Many variants — across products — point at one row here, and that is the whole
 * change.
 *
 * **The size lives here, not only on the variant.** A stock item *is* a material at a size: the
 * 25*35 pile and the 35*40 pile are two rows, two balances, two prices, bought on two purchase
 * order lines. Sharing runs across products at one size, never across sizes.
 *
 * `unit` moves here from `products.stock_unit`, which this change drops. A unit is a fact about
 * the pile, not about a product that draws on it: two products sharing this row cannot be allowed
 * to disagree about whether it is counted in pieces or weighed in kilos, and while `stock_unit`
 * sat on the product nothing stopped them. It stays independent of `products.pricing_unit` — what
 * the customer is charged by — exactly as before; see {@see PricingUnit}
 * and the note on {@see InventoryService::requiresWholeQuantities()}. Changed
 * after creation only through {@see SetStockItemUnit}.
 *
 * `code` is `S` + the row id, reserved from this table's sequence before insert by
 * {@see AllocateStockItemIdentifier} — the same move products and customers already make.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();

            $table->string('code', 20);

            // The material itself, without its size: «كيس شحن». The size is the two columns
            // below, so one name can carry a whole family of shelves.
            $table->string('name');

            // Null for a stock item that is not a size — a roll, an ink, anything counted
            // without dimensions. Stored apart from the name for the same reason
            // `product_variants` stores them apart from its label: so sizes sort and filter
            // numerically rather than as text.
            $table->unsignedSmallInteger('width_cm')->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();

            $table->string('unit', 20);   // PricingUnit

            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes()->index();

            // The picker's ordering, and the "which shelves are this size" lookup a variant's
            // stock-item chooser makes.
            $table->index(['width_cm', 'height_cm']);
        });

        // Partial, like every unique index in this schema: deleting a stock item releases its
        // code. See make_unique_indexes_ignore_soft_deleted_rows.
        DB::statement(
            'CREATE UNIQUE INDEX stock_items_code_unique
             ON stock_items (code) WHERE deleted_at IS NULL'
        );

        // One row per (material, size) — «كيس شحن» may exist at 25*35 and at 35*40 without
        // anyone having to invent two names for it.
        //
        // COALESCE rather than a bare (name, width_cm, height_cm): PostgreSQL treats NULLs as
        // distinct in a unique index, so two unsized rows both named «حبر أسود» would both
        // insert and the index would have guaranteed nothing. `NULLS NOT DISTINCT` would say it
        // more directly but needs PostgreSQL 15+, and nothing else in this schema requires that.
        DB::statement(
            'CREATE UNIQUE INDEX stock_items_name_size_unique
             ON stock_items (name, COALESCE(width_cm, 0), COALESCE(height_cm, 0))
             WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_items');
    }
};
