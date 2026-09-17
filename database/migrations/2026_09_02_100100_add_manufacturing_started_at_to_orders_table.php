<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a وسيط order was sent out to the vendor who makes it.
 *
 * Denormalised for the same reason its siblings are: `order_status_transitions` holds the whole
 * history, but «كم يوماً تقعد الطلبية عند المورد؟» — the first question this road will be asked —
 * should not have to walk it. `OrderStatus::timestampColumn()` is what stamps it, and that method
 * is the only place that knows this column exists.
 *
 * **Not shared with `printing_started_at`.** The two describe different events at different
 * desks, and one column holding both would lose which of them a date meant the moment a report
 * tried to separate our own press from a vendor's.
 *
 * Nullable, and no backfill: no order taken before this migration has ever been sent to a vendor,
 * and null says exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('manufacturing_started_at')->nullable()->after('printing_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('manufacturing_started_at');
        });
    }
};
