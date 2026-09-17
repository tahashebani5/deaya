<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deal is «D25», and nothing else.
 *
 * The name was a field somebody had to invent on a screen that already knew everything about the
 * thing being named — the order, the vendor, the shelves — and what it produced was «شحنة محمد
 * عمر» typed beside a code that identified the deal exactly. Two identities for one row, one of
 * them free text, and the free one is the one that would be searched, mistyped and disagreed with.
 *
 * The code has been the real name since the first migration: allocated from the id before the
 * insert, unique, and printed on every screen. Dropping the column removes the choice.
 *
 * **Irreversible in substance.** `down()` puts the column back so a rollback runs, but the names
 * that were typed are gone with the data — which is why this belongs on a branch that has not
 * shipped rather than on one that has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investor_deals', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('investor_deals', function (Blueprint $table) {
            $table->string('name', 120)->default('')->after('code');
        });
    }
};
