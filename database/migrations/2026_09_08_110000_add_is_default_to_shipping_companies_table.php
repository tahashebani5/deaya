<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The carrier a dispatch opens on.
 *
 * **Because «من سيأخذها» has the same answer nearly every time.** The app already filled the
 * field in when the business dealt with exactly one company — a list of one that still has to
 * be tapped is a tap that tells nobody anything — and stopped the moment a second was added,
 * which is most businesses: the shop deals with three carriers and sends nine parcels in ten
 * with the same one. Guessing that from a list is not possible; being told it is.
 *
 * **A fact about the list, not about the row.** At most one company may hold it, which the
 * partial unique index below is the last word on — the actions clear the old one in the same
 * transaction, and a row edited by any other route is refused rather than making a second
 * default that reports would have to pick between.
 *
 * `deleted_at IS NULL` in the index for the reason the name's index gives: a company removed by
 * mistake must not hold the flag hostage.
 *
 * The default is seeded onto النورس, which is the company this shop actually sends with — see
 * {@see seedTheDefault()} for how it is found, and why not by the carrier integration's setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_companies', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
        });

        // Indexed on the flag itself and filtered to the rows that carry it: every row in the
        // index therefore holds the same value, so "unique" reads as "at most one".
        DB::statement(
            'CREATE UNIQUE INDEX shipping_companies_single_default ON shipping_companies (is_default) '.
            'WHERE is_default AND deleted_at IS NULL'
        );

        self::seedTheDefault();
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS shipping_companies_single_default');

        Schema::table('shipping_companies', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }

    /**
     * Marks النورس, and says nothing if it cannot find it.
     *
     * **By name first, and the carrier's configured id only as a fallback.** The obvious order
     * is the other way round — `services.nawris.shipping_company_id` is a row somebody chose
     * deliberately — but that setting answers a different question: which company a *parcel
     * opened through the integration* is filed under. On this database it points at «درب
     * السبيل» while «النورس» is the company the shop actually sends with, and a default seeded
     * from it would have been the wrong carrier on every dispatch form in the shop.
     *
     * Either way it is one company or none: two rows answering to the name is a guess, and a
     * guessed default is worse than an empty box somebody fills in.
     */
    private static function seedTheDefault(): void
    {
        $named = DB::table('shipping_companies')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('name', 'ilike', '%نورس%')
            ->pluck('id');

        $configured = config('services.nawris.shipping_company_id');

        $id = match (true) {
            $named->count() === 1 => (int) $named->first(),
            $configured !== null && $configured !== '' => (int) $configured,
            default => null,
        };

        if ($id === null) {
            return;
        }

        DB::table('shipping_companies')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->update(['is_default' => true]);
    }
};
