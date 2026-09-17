<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The companies that carry our parcels.
 *
 * **This used to be a string typed onto the order, and that is the problem it solves.** Free
 * text meant «درب» and «شركة درب» and «درب للشحن» were three different carriers as far as any
 * report was concerned, nobody could be rung without opening an old order to find the number,
 * and there was no way to say "we stopped dealing with them" short of remembering.
 *
 * `is_active` rather than deletion for the ordinary case: a company we no longer use still
 * carried a hundred parcels last year, and those orders must keep naming it. Deleting is kept
 * for the row that should never have existed — a typo, a duplicate — and it soft-deletes like
 * everything else here.
 *
 * The parcel-level details (`tracking_number`, the courier's phone) stay on the order. They
 * belong to one parcel, not to the company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_companies', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);

            // The office you ring when a parcel goes missing. Nullable because a company can be
            // added the moment it is needed, from a screen where nobody has the number to hand.
            $table->string('phone', 20)->nullable();

            $table->text('notes')->nullable();

            // Whether it is offered on a new dispatch. Old orders naming it are unaffected.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes()->index();

            // The picker's own query: the active ones, in name order.
            $table->index(['is_active', 'name']);
        });

        // Partial, like every unique index here: a plain one would count soft-deleted rows, so
        // a company removed by mistake would hold its name hostage forever.
        DB::statement(
            'CREATE UNIQUE INDEX shipping_companies_name_unique ON shipping_companies (name) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_companies');
    }
};
