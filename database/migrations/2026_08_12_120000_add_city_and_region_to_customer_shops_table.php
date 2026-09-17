<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A shop's موقع becomes a place on the delivery map — city and neighbourhood — instead of a pair
 * of coordinates nobody could read.
 *
 * The pin is *not* dropped. `latitude` and `longitude` stay exactly as they are, holding what was
 * already recorded, and the endpoint still accepts them; only the form stopped asking. Deleting
 * columns to save a screen from showing them is how data with no other source disappears.
 *
 * **`city_id` is NOT NULL, `region_id` is not.** A shop that names no place is the thing this
 * change exists to prevent, so the schema says it rather than a comment promising a follow-up
 * migration. The neighbourhood stays optional in both directions: most cities on the map have
 * none, and a clerk taking a customer over the phone often does not know the district yet —
 * `is_region_required` is a *delivery* rule, and the order is what has to answer it.
 *
 * **`restrictOnDelete`, not `nullOnDelete`.** A city is soft-deleted in practice, so this
 * constraint should never fire; if some future path force-deletes one out from under a shop, a
 * refused delete is the right outcome — the alternative on a NOT NULL column is a failed write
 * in the middle of somebody else's transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_shops', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable()->after('name')->constrained()->restrictOnDelete();
            $table->foreignId('region_id')->nullable()->after('city_id')->constrained()->nullOnDelete();
        });

        $this->giveExistingShopsACity();

        Schema::table('customer_shops', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('customer_shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_id');
            $table->dropConstrainedForeignId('region_id');
        });
    }

    /**
     * Puts every shop recorded before this migration in the first city on the map.
     *
     * This *is* a guess, and it is only defensible because of what these rows are: demo and
     * trial data entered before the product had customers. Nothing here has reached production,
     * so the choice is between one visible wrong city an operator can correct in the customer's
     * own screen, and a column that cannot state its own rule for as long as those rows survive.
     *
     * On a fresh database there is nothing to guess about — the table is empty and this does
     * nothing at all.
     */
    private function giveExistingShopsACity(): void
    {
        $shopsWithoutACity = DB::table('customer_shops')->whereNull('city_id')->count();

        if ($shopsWithoutACity === 0) {
            return;
        }

        $firstCity = DB::table('cities')->whereNull('deleted_at')->orderBy('id')->value('id');

        // Loud rather than clever: making the column NOT NULL would fail a statement later with
        // a message about a constraint, and this says what actually has to be done about it.
        if ($firstCity === null) {
            throw new RuntimeException(
                "Cannot give {$shopsWithoutACity} existing shop(s) a city: the delivery map is ".
                'empty. Run `php artisan db:seed --class=DeliveryLocationSeeder` first.'
            );
        }

        DB::table('customer_shops')->whereNull('city_id')->update(['city_id' => $firstCity]);
    }
};
