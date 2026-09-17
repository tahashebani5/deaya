<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مجال العمل — what a customer's shop actually sells: شحن, بيع ملابس, مطاعم ومقاهي …
 *
 * **Stored now so it can be asked later.** Nothing in the app reads this yet; the value is
 * entirely in the accumulation. Six months of orders with a field on every shop answers «كم
 * عميلاً في الشحن؟» and «أي مقاس تطلبه محلات الملابس؟» from data we already have, where the
 * alternative is ringing four hundred customers to ask.
 *
 * **A table, not an enum.** The list is the business's to shape — it will grow the first week
 * somebody records a customer nobody thought of — and an enum would make each addition a
 * deployment. It is also why this is a normal CRUD with its own permissions rather than a
 * seeder-only lookup.
 *
 * `is_active` rather than deletion for the ordinary case: a field we stop offering is still
 * the truth about the shops already carrying it. Deleting is kept for the row that should
 * never have existed — a typo, a duplicate — and it is refused outright once any shop points
 * at it, because a shop pointing at a hidden row is worse than a list with one extra entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_fields', function (Blueprint $table) {
            $table->id();

            // What the staff member picks from a list, and what a report will group by. The
            // unique index is added below rather than here — see the comment on it.
            $table->string('name', 100);

            // Whether it is still offered when recording a shop. Shops already on it are
            // unaffected: this hides the row from the picker, it does not retract it.
            $table->boolean('is_active')->default(true);

            // The picker's order. The three or four fields most customers are in belong at the
            // top; alphabetical order of Arabic names would bury them and depends on the
            // database's collation.
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            // Every model here soft deletes. Added in the table rather than by a later
            // migration, unlike the tables that predate that rule.
            $table->softDeletes();
        });

        // Partial, exactly as every other unique index in this schema is: `deleted_at` says a
        // row is gone while a plain unique index still counts it, so deleting «شحن» and adding
        // it back would fail against a row the API insists does not exist — and fail as a 500
        // from a constraint violation rather than the readable 422 the `unique:` rule produces.
        //
        // Named by Laravel's own convention so `dropUnique(['name'])` still finds it.
        DB::statement(
            'CREATE UNIQUE INDEX business_fields_name_unique ON business_fields (name) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('business_fields');
    }
};
