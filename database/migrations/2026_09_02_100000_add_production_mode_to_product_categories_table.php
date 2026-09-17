<?php

use App\Domain\Catalog\Enums\ProductionMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `skips_production` grows a third answer and becomes `production_mode`.
 *
 * The boolean asked «هل يُطبع؟» and the shop now has three answers, not two — what we print, what
 * we pull off a shelf, and what a vendor makes for us. See
 * {@see ProductionMode} for why that became one vocabulary rather than
 * a second boolean beside the first.
 *
 * **The backfill is the whole of the compatibility story.** `true → none` and `false → in_house`
 * is the mapping the old column always meant, so every heading comes out of this migration
 * deciding exactly what it decided before it — and therefore so does every order taken after it.
 * No row is turned `outsourced` here: that is a decision the business takes, on a heading it
 * creates or edits, and a migration inventing it would be a migration guessing.
 *
 * Forward-only per RULES.md §8: `down()` restores the boolean so a rollback in development is not
 * a dead end, and «وسيط» collapses to `false` there — the only honest answer a boolean has for it,
 * and the reason rolling back past this point loses information that cannot be recovered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            // Where the boolean sat, and for the same reason: read together with `is_active`,
            // one saying whether the heading is offered and the other what it means.
            $table->string('production_mode', 20)
                ->default(ProductionMode::InHouse->value)
                ->after('is_active');
        });

        // The mapping the boolean always meant. Written as two statements rather than one CASE so
        // each says which fact it is preserving.
        DB::table('product_categories')
            ->where('skips_production', true)
            ->update(['production_mode' => ProductionMode::None->value]);

        DB::table('product_categories')
            ->where('skips_production', false)
            ->update(['production_mode' => ProductionMode::InHouse->value]);

        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn('skips_production');
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->boolean('skips_production')->default(false)->after('is_active');
        });

        // «وسيط» has no boolean to go back to: it neither skips production nor is printed here.
        // It comes back as `false`, which is the safe direction — the road that asks more of the
        // shop — and it is why this rollback is lossy and says so.
        DB::table('product_categories')
            ->where('production_mode', ProductionMode::None->value)
            ->update(['skips_production' => true]);

        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn('production_mode');
        });
    }
};
