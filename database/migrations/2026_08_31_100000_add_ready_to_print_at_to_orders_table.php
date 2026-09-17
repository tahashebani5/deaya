<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the warehouse finished with an order and handed it to the press.
 *
 * **The metric «جاهزة للطباعة» was added to produce.** The status draws a line between two
 * departments; without a column, «كم تقعد الطلبية بين المخزن والمطبعة؟» could only be answered by
 * walking `order_status_transitions`, which is exactly the cost the other milestone columns on
 * this table exist to avoid.
 *
 * Denormalised on the same terms as `printing_started_at` and `ready_at` beside it, and honest for
 * the same reason: nothing ever returns to «جاهزة للطباعة» — see `OrderStatus::allowedNext()` — so
 * one column holds one visit and cannot quietly lose an earlier one, which is precisely why
 * «نواقص» and «إعادة إرسال» have no column of their own.
 *
 * **Nothing is backfilled**, and null is the truthful answer for every row already in the table:
 * those orders were taken before the handover existed and genuinely never passed through it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // In the order the milestones happen, so the row reads like the journey.
            $table->timestamp('ready_to_print_at')->nullable()->after('placed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('ready_to_print_at');
        });
    }
};
