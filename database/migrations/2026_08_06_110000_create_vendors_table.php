<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier the business buys stock from.
 *
 * Deactivated, never deleted — the same rule {@see Customer} follows and for the same reason:
 * every {@see StockArrival} on record points at a vendor row, and deleting one would leave that
 * history pointing at nothing. There is deliberately no destroy route.
 *
 * `phone` is unique the same way a customer's is: one number identifies one vendor. Partial,
 * like every unique index in this schema, so a deactivated-then-removed vendor's number is not
 * stuck forever — though in practice a vendor never leaves this table, only `is_active`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            // Who to call at the vendor. Optional: some vendors are dealt with as a business,
            // not through a named contact.
            $table->string('contact_person')->nullable();

            $table->string('phone', 20);

            $table->string('email')->nullable();

            $table->string('address')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
            $table->softDeletes()->index();
        });

        // One phone number, one vendor — the same guarantee `customers.phone` makes, held at
        // the database level rather than only in validation, which loses to two concurrent
        // requests that both pass the existence check before either commits.
        DB::statement(
            'CREATE UNIQUE INDEX vendors_phone_unique
             ON vendors (phone) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
