<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each of a customer's shops sells.
 *
 * **On the shop, not on the customer.** One customer may own a clothes shop and a perfume
 * shop; hanging the field off the customer would force a choice between them and record a
 * fact about neither.
 *
 * **Nullable, and not by laziness:** every shop already in the table was recorded before this
 * existed, and NOT NULL would mean inventing a field for each of them. Null reads as «لم
 * يُحدَّد» — which is also the honest answer for a shop recorded in a hurry.
 *
 * `nullOnDelete` is the safety net under the API's own rule. The endpoint refuses to delete a
 * field any shop still points at, so this should never fire; if a row is ever removed straight
 * from the database, the shops survive with an empty field instead of a dangling key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_shops', function (Blueprint $table) {
            $table->foreignId('business_field_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('business_fields')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_field_id');
        });
    }
};
