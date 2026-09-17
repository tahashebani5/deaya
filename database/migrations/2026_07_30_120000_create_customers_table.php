<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            // Human-facing identifier: 'C' followed by the row's id (C1, C2, C3 …).
            // Stored rather than derived so it can be indexed, searched, exported, and
            // so legacy or hand-assigned codes remain possible later.
            $table->string('code', 20)->unique();

            $table->string('name');

            // Indexed, not unique: staff look customers up by phone constantly. Whether two
            // customers may share a contact number is a business rule, not a schema one.
            $table->string('primary_phone', 20)->index();

            // Customers are deactivated rather than deleted, so history stays intact.
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
