<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What came back for the order, and when it was agreed.
 *
 * Handing the bags over and being paid for them are two different events, and until now the
 * machine only recorded the first: «تم الاستلام» was where an order stopped. But an order sent
 * out with a courier is money owed by that courier, and the day the cash comes back — or comes
 * back short — is the day the job is actually finished. That is «تم التسوية».
 *
 * `collected_amount` is nullable and stays null on the ordinary settlement, where what came back
 * is exactly `grand_total`. It is filled only when the two differ, so a number in this column
 * always means "this is not what the invoice said" and can be reported on as such. A column
 * defaulted to the total would have made the common case indistinguishable from a mistake.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Two places, like every other money column here: these are compared against
            // `grand_total`, and a comparison between a decimal and a float is a coin toss.
            $table->decimal('collected_amount', 12, 2)->nullable()->after('weight_kg');

            $table->timestamp('settled_at')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['collected_amount', 'settled_at']);
        });
    }
};
