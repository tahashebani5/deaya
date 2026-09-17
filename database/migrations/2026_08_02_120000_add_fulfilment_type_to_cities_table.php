<?php

use App\Domain\Delivery\Enums\FulfilmentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a place on the map is delivered to or collected from, as a stored fact.
 *
 * Until now the two إستلام مكتب branches were distinguishable only by their **name**. Orders are
 * about to depend on the distinction — leaving the workshop moves an order to «استلام مكتب» for
 * one kind of city and «جاري التوصيل» for the other, and the server decides which — so keying
 * that off an Arabic display name would mean an administrator fixing a typo silently breaks the
 * status of orders taken days later.
 *
 * **`delivery` as the default is what makes this migration safe on a live table.** Ninety-three
 * of the ninety-five rows are already that, the column is NOT NULL from the first moment, and no
 * existing row is left in an undefined state waiting for a backfill that might not run.
 *
 * **The one-time backfill matches on the name, which is exactly what the column exists to stop.**
 * That is not a contradiction: the name is being read *once*, here, against the data as it stands
 * today, to record an answer that is then never derived again. Reading it at runtime is the thing
 * being fixed. If the business has since renamed a branch, this misses it and the fix is an
 * administrator flipping the field — which is now possible, and was the point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->string('fulfilment_type', 20)
                ->default(FulfilmentType::Delivery->value)
                ->after('name');

            // Indexed: the order screen asks for the pickup branches by themselves, and the
            // status rules read this column on every dispatch.
            $table->index('fulfilment_type');
        });

        DB::table('cities')
            ->where('name', 'like', 'إستلام مكتب%')
            ->update(['fulfilment_type' => FulfilmentType::OfficePickup->value]);
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropIndex(['fulfilment_type']);
            $table->dropColumn('fulfilment_type');
        });
    }
};
