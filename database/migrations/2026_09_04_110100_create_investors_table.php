<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The party who puts money into our stock.
 *
 * **Separate from `users` on purpose.** Most investors will never sign in, and `users` is
 * employee-shaped: `User::booted()` burns an `employee_code` from a sequence for every row, and
 * both `email` and `phone` are required and unique. `user_id` is the optional link for the ones
 * who do sign in, and it is also the *only* thing that confines the read-only portal to one
 * investor's own rows — there are no policies in this application, so row scoping is done by
 * removing the surface, not by a permission.
 *
 * **No unique index on `name`.** This is a register of people, not a curated reference list:
 * two investors may genuinely share a common Libyan name, and `phone` is nullable so it cannot
 * carry the disambiguation either. `customers` is the closer analogue and is not uniquely
 * named. The code is what staff say out loud.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investors', function (Blueprint $table) {
            $table->id();

            // «I7» — allocated from the id the way an order's number is, and kept in its own
            // column so the format can gain a year later without touching the primary key.
            $table->string('code', 12);

            $table->string('name', 100);
            $table->string('phone', 20)->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();

            // Retired, never deleted — the `business_fields` rule. An investor with history is
            // not removable, and a row nobody should have created is a different problem.
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['is_active', 'name']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX investors_code_unique ON investors (code)
            WHERE deleted_at IS NULL
        SQL);

        // One login belongs to one investor. A database fact rather than a rule in an action,
        // because it is what the portal's whole confinement rests on.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX investors_user_id_unique ON investors (user_id)
            WHERE user_id IS NOT NULL AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('investors');
    }
};
