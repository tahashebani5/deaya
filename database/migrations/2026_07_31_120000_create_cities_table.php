<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an order can be delivered — طرابلس, مصراتة, بنغازي … and the two office-pickup branches.
 *
 * The list is transcribed from the delivery map the business already runs
 * (Info/Schema-from-other-apps/Cities&Regions), so the prices here are the ones actually quoted.
 *
 * **`delivery_price`, not `price`.** A bare `price` on a row that sits next to product prices and
 * price tiers reads as "the price of the city". The column names what it is: the cost of
 * delivering there.
 *
 * **The price is nullable**, and that is deliberate rather than lazy: three cities in the source
 * (تساوة, ضواحي الزاوية, ضواحي صبراتة) were added before a rate was agreed for them. Seeding a
 * `0.00` would silently mean "free delivery"; null means "not priced yet", which the quoting code
 * can refuse to guess at. Tighten to NOT NULL once the business sets those three rates.
 *
 * **`is_region_required`** says the customer *must* choose a region — it does not say the city has
 * any. Two cities carry regions without requiring one, so the flag is stored rather than derived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();

            // Unique: this Arabic name is what the customer picks from, and what the seeder
            // matches on when it re-runs, so two rows sharing one would make both ambiguous.
            $table->string('name', 100)->unique();

            $table->boolean('is_region_required')->default(false);

            $table->decimal('delivery_price', 10, 2)->nullable();

            // The شركة درب branch that serves this city — "زناتة، طرابلس". Free text on purpose:
            // it belongs to the shipping company's own list, which we neither own nor validate.
            $table->string('darb_branch')->nullable();

            // decimal(10, 7) rather than a float, matching customer_shops: 3 integer digits cover
            // longitude's -180..180 and 7 decimals resolve to about a centimetre, with none of the
            // drift a binary float introduces. Nullable because most cities are a delivery area
            // rather than a point, and inventing 0,0 would drop them in the Gulf of Guinea.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
