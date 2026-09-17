<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A line may arrive without a price, so a request for something priced «حسب الطلب» can be placed
 * from the app at all.
 *
 * **Null, not zero, and the difference is the whole migration.** A product the catalogue prices
 * on request has no number to fall back on; the customer cannot be shown one and must not invent
 * one. Storing `0` would make "this has not been priced yet" indistinguishable from "this line is
 * free" — and an order that slipped past review carrying zeroes would invoice nothing, silently,
 * with no column able to say it was wrong. Null cannot be added up by accident.
 *
 * **What keeps null from spreading:** `ChangeOrderStatus` refuses «بانتظار المراجعة» → «جديدة»
 * while any line is unpriced, and the prices are collected on that very move — see
 * {@see \App\Domain\Order\Support\TransitionFields}. So an unpriced line exists only on an order
 * nobody has accepted, which is the one state where no invoice, no payment and no report reads
 * it.
 *
 * `orders.items_total` and `grand_total` stay NOT NULL. They are 0 on such an order, and that 0
 * is contained by the same invariant: an order nobody accepted has no total anyone is entitled
 * to read, and the resources send null rather than the figure — see `hasUnpricedLines()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('unit_price', 12, 3)->nullable()->change();
            $table->decimal('line_total', 14, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Anything still unpriced cannot be expressed by the old shape. Zeroing is the only
        // reversal available, and it is why this migration is easier to apply than to undo.
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('unit_price', 12, 3)->default(0)->nullable(false)->change();
            $table->decimal('line_total', 14, 2)->default(0)->nullable(false)->change();
        });
    }
};
