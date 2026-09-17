<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Nawris calls the places we already have names for.
 *
 * **Their geography mapped onto ours, rather than typed in beside it.** Nawris has a
 * `government` and an `area`; we have a city and a region, and an order already knows which. So
 * an operator dispatching a parcel never picks a Nawris destination — the order's own city
 * carries it. See NAWRIS-INTEGRATION.md §4.
 *
 * **There is precedent on both tables**, which is the argument for putting them here rather than
 * inventing a mapping table: `cities.darb_branch` and `regions.code` are already another
 * carrier's own vocabulary parked on our row, unvalidated because we neither own it nor get to
 * define it. These are the same kind of column for a different carrier.
 *
 * **String rather than a foreign key or an integer**, for the same reason those two are strings:
 * the value belongs to Nawris. We store what they gave us and send it back verbatim. An integer
 * column would be a claim about a format we have not seen — the contract calls `government` a
 * string in the payload while the area lookup is addressed as `get-area/{id}`, and until a real
 * call settles which we are looking at, a string stores both honestly.
 *
 * **Nullable, and that is load-bearing rather than lenient.** Most cities are not Nawris
 * destinations at all — the two «استلام مكتب» rows never leave the building, and the business may
 * use Nawris for some cities and its own courier for others. A delivery city without a mapping is
 * refused *at dispatch*, by name, rather than being allowed to send a null the carrier would
 * reject with something unreadable.
 *
 * Indexed on the city, because the webhook side and the dispatch guard both ask "is this
 * destination mapped?" and neither wants a table scan to find out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->string('nawris_government_id', 40)->nullable()->after('darb_branch');

            // Partial would be wrong here: two cities legitimately share a government, exactly as
            // several of ours share a درب branch. This is for the "mapped or not" question.
            $table->index('nawris_government_id');
        });

        Schema::table('regions', function (Blueprint $table) {
            $table->string('nawris_area_id', 40)->nullable()->after('darb_branch');
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropIndex(['nawris_government_id']);
            $table->dropColumn('nawris_government_id');
        });

        Schema::table('regions', function (Blueprint $table) {
            $table->dropColumn('nawris_area_id');
        });
    }
};
