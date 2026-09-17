<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shop's الموقع becomes real coordinates instead of free text, so distances and visit
 * ordering can be computed later.
 *
 * decimal(10, 7) rather than a float: 3 integer digits cover longitude's -180..180, and 7
 * decimal places resolve to about 1 cm — far beyond what a shop pin needs, with none of the
 * drift a binary float introduces when values are compared or summed.
 *
 * The columns are nullable while the existing rows have no coordinates: a written address
 * cannot be turned into a latitude and longitude, so there is no honest backfill, and
 * inventing a default such as 0,0 would place every shop in the Gulf of Guinea. Both fields
 * are *required by validation*, so nothing new can be created without them. Once the older
 * shops have been re-entered, a one-line follow-up migration can make the columns NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_shops', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->dropColumn('location');
        });
    }

    public function down(): void
    {
        Schema::table('customer_shops', function (Blueprint $table) {
            // Restored nullable: the text this column held cannot be recovered from
            // coordinates, so pretending it is required again would fail on every row.
            $table->string('location')->nullable();
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
