<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A neighbourhood inside a city — سوق الجمعة, زناتة, السبعة …
 *
 * A region has no price of its own: delivery is priced per city, and the region only narrows down
 * *where* in that city, plus which شركة درب branch and zone code the parcel is routed through.
 *
 * Regions belong entirely to their city — no independent lifecycle, and they go when it goes.
 *
 * The unique index on (city_id, name) is the reason the seeder drops two rows from the source
 * export: ضواحي الزاوية holds البرناوي and قرية ناصر twice each, created seconds apart with the
 * same branch — a double-submitted form, not two real places. A picker that lists the same
 * neighbourhood twice is a defect, so the constraint is applied and the seeder seeds each once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();

            $table->string('name', 100);

            // شركة درب's own zone code — "s18", "s21". Null wherever the branch has not published
            // one; it is their identifier, so it is stored as received and never generated here.
            $table->string('code', 20)->nullable();
            $table->string('darb_branch')->nullable();

            // Optional pin, same shape and reasoning as on cities.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->timestamps();

            // One city cannot list the same neighbourhood twice. Validation gives the readable
            // 422; this index is what actually holds under two concurrent writes.
            $table->unique(['city_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
