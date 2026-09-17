<?php

use App\Domain\Inventory\InventoryService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The unit belongs to the pile, not to a product that draws on it.
 *
 * `stock_unit` was added to `products` nine days ago to let a product bought in by weight be sold
 * by the piece, and that requirement has not changed — it has moved. Now that كيس شحن سادة and
 * كيس شحن مطبوع share one shelf, leaving the column here would let two products insist that one
 * pile of bags is counted two different ways, and whichever movement ran first would decide what
 * the balance meant. `stock_items.unit` is the same value with exactly one owner.
 *
 * **`pricing_unit` stays.** It is the other half of the pair and always was: what the customer is
 * charged by, which has nothing to do with what the shelf is counted in. The deliberate
 * disagreement between the two — whole numbers on an order, fractional weight off a shelf —
 * survives intact; only the second half is read from somewhere else. See
 * {@see InventoryService::requiresWholeQuantities()}.
 *
 * Not reversible in any meaningful sense: `down()` restores the column and backfills it from
 * `pricing_unit`, which is what the original migration did for existing rows, but a unit that had
 * since been changed on the stock item is not recoverable from here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('stock_unit');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('stock_unit', 20)->nullable()->after('pricing_unit');
        });

        DB::statement('UPDATE products SET stock_unit = pricing_unit');
        DB::statement('ALTER TABLE products ALTER COLUMN stock_unit SET NOT NULL');
    }
};
