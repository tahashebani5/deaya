<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the employee's own screen needs: what they are paid, and whether the account is
 * still in use.
 *
 * **`salary` is nullable, and null is an answer.** «لم يُحدَّد» is a real state — an account
 * created for somebody whose wage has not been agreed yet — and a nought would read as a wage
 * of nothing on the same screen. `decimal(12,2)` for the same reason every money column in this
 * schema is a decimal: a wage is counted in dinars and half-dinars, never in floats.
 *
 * **`is_active` rather than the soft delete that is already on this model.** Deleting hides the
 * row behind a global scope, so a stopped employee would vanish from the list that is the only
 * place able to put them back. Deactivating is what every other record in this system does —
 * the customer, the product, the business field — and it is reversible by design. The soft
 * delete stays underneath as the floor: it is still what makes removing a row safe.
 *
 * Existing rows default to active, which is what they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('salary', 12, 2)->nullable()->after('employee_code');
            $table->boolean('is_active')->default(true)->after('salary');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['salary', 'is_active']);
        });
    }
};
