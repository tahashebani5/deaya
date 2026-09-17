<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The parcel weight goes, because nothing was ever computed from it.
 *
 * It was asked for on the way into «جاهزة» — required, even, for any order carrying a line
 * priced by the kilo, on the grounds that «الوزن هو ما تُحاسب عليه». That was never true of the
 * code: an invoice is built from the line quantities (see `OrderItem::billableQuantity()`), the
 * carrier never read this, and costing never knew it existed. Its only two readers were the API
 * resource that echoed it and one fact row on the order screen — both gone with the field.
 *
 * What comes off the shelf is a different question, and it is now asked line by line and only
 * where nobody could work it out — see READY-DEDUCTION-PER-LINE.md.
 *
 * **`down()` restores the column, not the numbers.** Dropping a column takes its data with it;
 * a rollback gives back the shape and every row reads null. Nothing depends on that data, which
 * is why this is safe to run — but it is worth saying plainly rather than implying that a
 * reversible migration means a reversible deletion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('weight_kg');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('weight_kg', 12, 3)->nullable()->after('grand_total');
        });
    }
};
