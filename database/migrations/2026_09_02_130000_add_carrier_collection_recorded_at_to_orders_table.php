<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The flag that stops a repeated delivery notice being paid twice.
 *
 * **It lives on the order, not on the parcel and not on the webhook event, and that placement is
 * the whole point.** It has to survive the parcel being deleted, re-created, or the order being
 * re-dispatched under a new code — all of which happen — and an idempotence flag that disappears
 * with the thing it was guarding against is not a guard at all.
 *
 * A duplicate webhook is routine: they carry no delivery guarantee and no replay id, so a
 * re-send of the same news is expected traffic rather than a fault. Two layers already stand in
 * front of this one — a unique fingerprint on `nawris_webhook_events`, and a status comparison in
 * `ApplyNawrisStatus` — and this is the third, because the first two both fail open in ways that
 * are hard to see: a body that differs by one character has a different fingerprint, and the
 * status comparison is skipped entirely on the path where a second event arrives before the first
 * has committed.
 *
 * It guards **both** money entries written on code 7 — the cash Nawris remits and the delivery fee
 * the customer paid the courier — because either one written twice is money invented.
 *
 * Nullable rather than a boolean: *when* the collection was recorded is worth knowing when
 * somebody is reconciling a day's takings, and null already means "not yet".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('carrier_collection_recorded_at')
                ->nullable()
                ->after('carrier_settled_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('carrier_collection_recorded_at');
        });
    }
};
