<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which carrier took the parcel, and the number of the man holding it.
 *
 * `shipping_company` has been a free-text column since the orders table was created, and nothing
 * ever wrote to it — which was lucky, because free text meant «درب» and «شركة درب» were two
 * carriers as far as any report was concerned. It now becomes what `city_name` already is: a
 * **snapshot** written beside a foreign key. The key is for filtering and for opening the live
 * record; the name is what the order said on the day, and deleting or renaming a company never
 * rewrites it.
 *
 * `courier_phone` is the man, not the company — the number you ring when the parcel is late.
 * Kept on the order rather than on the company because it changes with every dispatch: a
 * different driver carries it each time.
 *
 * The FK is deliberately without a cascade. A carrier soft-deletes, so the row survives and the
 * key keeps resolving; and even a hard delete must leave the order's own account of itself
 * intact, which the snapshot beside it guarantees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('shipping_company_id')
                ->nullable()
                ->after('shipping_company')
                ->constrained('shipping_companies');

            $table->string('courier_phone', 20)->nullable()->after('courier_name');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipping_company_id');
            $table->dropColumn('courier_phone');
        });
    }
};
