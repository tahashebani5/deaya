<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a customer signs in to the app with.
 *
 * **Nullable, and most rows will keep it null forever.** Every customer in this table was typed
 * in by a clerk who had their name and their phone and nothing else; a password appears only when
 * that person installs the app and registers. So «لا كلمة مرور» is the ordinary state of a
 * customer, not a half-finished one — and `AuthenticateCustomer` treats a null here as wrong
 * credentials rather than as an account to let through.
 *
 * **No `email` column, deliberately.** This is the whole reason the customer app does not reuse
 * `users`: that table requires a unique email of everybody, and a shopkeeper registering from his
 * phone in Benghazi has none to give. `customers.phone` is already unique — see
 * `make_customer_primary_phone_unique` — so it is the login identifier, and there is nothing else
 * to add.
 *
 * **No `remember_token` either.** That column belongs to session authentication; this account
 * only ever authenticates by Sanctum token, and the model turns the remember-token machinery off
 * rather than carry a column nothing writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('password')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};
