<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_shops', function (Blueprint $table) {
            $table->id();

            // A shop has no meaning without its customer, so it goes when the customer does.
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            // اسم المكان
            $table->string('name');

            // الموقع — a free-text address / area description, not coordinates.
            $table->string('location');

            // رابط الصفحة — optional; not every shop has a social page.
            $table->string('page_url')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_shops');
    }
};
