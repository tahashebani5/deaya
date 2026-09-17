<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two numbers a run produces on its way off the press.
 *
 * **The weight is the order's; the shortage is the line's.** A parcel is weighed once — the
 * courier charges for one box — while «ناقص» is always a question about a size: forty of the
 * 30*30 and none of the 45*50 is the ordinary case, and a single column on the order could not
 * say it.
 *
 * Both are decimal(12,3) rather than float, and to three places, because the catalogue prices
 * quantities that way: a kilogram order is 12.500 and rounding it to 12.5 in one place and 13
 * in another is how an invoice stops adding up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Filled on the way into «جاهزة» — required when the order is priced by the kilo,
            // because then the scale *is* the invoice.
            $table->decimal('weight_kg', 12, 3)->nullable()->after('grand_total');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            // What is missing from this line, in the line's own unit. Nullable and not zero:
            // «nothing was recorded» and «nothing is missing» are different facts, and only one
            // of them means somebody has counted.
            $table->decimal('shortage_quantity', 12, 3)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('weight_kg');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('shortage_quantity');
        });
    }
};
