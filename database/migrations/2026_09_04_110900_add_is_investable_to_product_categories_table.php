<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which headings a deal may be opened against.
 *
 * On the category rather than on the product, for the reason `production_mode` gives on the row
 * above it: one row edited once, instead of a box on every product edited forever. And the two
 * cannot then contradict each other, which is what a second boolean on `products` would invite.
 *
 * **Nullable, three-state, and that is the point.** `production_mode` resolves by *override* —
 * the child wins when it holds anything but the default, and the parent answers otherwise. A
 * `NOT NULL DEFAULT false` boolean cannot express that: it has no way to say «اسأل أبي» as
 * distinct from «لا», so a leaf deliberately excluded from an investable family would still
 * answer yes. Null inherits; a real value decides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->boolean('is_investable')->nullable()->after('production_mode');
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn('is_investable');
        });
    }
};
