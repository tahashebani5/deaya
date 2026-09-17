<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refusing a request from the app, recorded apart from writing an order off.
 *
 * **Its own reason column, not `cancellation_reason`.** The two answer different questions and
 * have different readers. `cancellation_reason` is why the shop wrote off an order it had taken
 * — internal, and it sits in the write-off reporting. This one is the shop's answer *to the
 * customer*, and it leaves for their phone. Sharing a column would put a sentence written for
 * the accountant on somebody's screen, and would make both counts wrong the moment anybody
 * asked "how many did we cancel?".
 *
 * **And its own timestamp**, for the same reason `manufacturing_started_at` is not
 * `printing_started_at`: «كم طلباً نرفض؟» and «كم طلبية نلغي؟» are two questions, and one column
 * holding both can answer neither.
 *
 * Both nullable, and nothing is back-filled. Orders already sitting in «إلغاء تام» that were
 * really refused requests stay where they are — rewriting them would change history that people
 * have already reported on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('request_rejected_at')->nullable()->after('cancelled_at');
            $table->text('rejection_reason')->nullable()->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['request_rejected_at', 'rejection_reason']);
        });
    }
};
