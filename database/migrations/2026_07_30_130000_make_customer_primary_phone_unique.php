<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two customers may never share a phone number — one number identifies one customer.
 *
 * Enforced in the database, not only in validation: a unique index is the only thing that
 * holds under two concurrent requests, where both would pass a "does this phone exist?"
 * check before either has committed.
 *
 * Deliberately contains no data cleanup. If an environment already holds duplicate numbers
 * this migration fails, which is the correct outcome — deciding which of two real customers
 * to keep is a business decision, not something a schema change should do silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // The plain lookup index is redundant once a unique index exists: PostgreSQL
            // serves the same lookups from the unique index's own b-tree.
            $table->dropIndex('customers_primary_phone_index');
            $table->unique('primary_phone');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_primary_phone_unique');
            $table->index('primary_phone');
        });
    }
};
