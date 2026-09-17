<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `primary_phone` implies a secondary one exists. A customer has exactly one number, so the
 * column is simply `phone`.
 *
 * The unique index is renamed alongside it — PostgreSQL keeps an index's original name through
 * a column rename, which would leave `customers_primary_phone_unique` sitting on a column
 * called `phone` and mislead the next person reading the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->renameColumn('primary_phone', 'phone');
            $table->renameIndex('customers_primary_phone_unique', 'customers_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->renameColumn('phone', 'primary_phone');
            $table->renameIndex('customers_phone_unique', 'customers_primary_phone_unique');
        });
    }
};
