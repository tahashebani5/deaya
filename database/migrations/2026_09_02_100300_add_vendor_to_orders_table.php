<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is making a وسيط order — chosen from the vendor list, never typed.
 *
 * **A pair, exactly like the city and the branch beside it.** `vendor_id` is the link a screen
 * follows and a report groups by; `vendor_name` is what this order said at the time. A vendor
 * renamed next year must not rewrite an order taken this year, and that is the whole reason the
 * name is stored rather than joined — see the same treatment on `city_name` and
 * `customer_shop_name`.
 *
 * `restrictOnDelete` rather than a cascade or a null: a vendor with orders against them is not a
 * row anybody may remove, and an order that lost the name of who made it is unanswerable. Vendors
 * soft-delete in practice, so this is the floor under that rather than the ordinary path.
 *
 * **Nullable, and required only by the domain.** Whether an order owes a vendor depends on the
 * road it walks, which is not known until its lines exist — so the refusal lives in `CreateOrder`
 * where the flow has been resolved, not in a column that would have to be right about every order
 * ever taken. Every existing order gets null, which is exactly true of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('customer_shop_name')
                ->constrained('vendors')->restrictOnDelete();
            $table->string('vendor_name')->nullable()->after('vendor_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropColumn('vendor_name');
        });
    }
};
