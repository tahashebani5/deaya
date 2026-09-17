<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A parcel as Nawris knows it: one handover, one code, one journey.
 *
 * **The parcel gets a row of its own, and that is the main thing this schema does differently
 * from the system the contract was compiled from.** There, one table held a row per *order* and
 * the parcel existed only as "all the rows sharing this code" — which is where most of that
 * integration's awkwardness came from. Here the parcel is a record, and
 * {@see nawris_parcel_orders} says which orders are in it.
 *
 * **Every money and destination column is a snapshot of what we sent**, for the same reason
 * `orders.city_name` is: an edit has to replay exactly what creation said, and re-deriving it
 * later moves the parcel or re-bills the customer. `delivery_price_deducted` in particular is
 * frozen here rather than looked up live at comparison time — a tariff change must not
 * retroactively rewrite what an old parcel was asked to collect.
 *
 * `closed_at` is what makes «ما زال في الطريق» a query rather than a status list somebody has to
 * keep in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawris_parcels', function (Blueprint $table) {
            $table->id();

            // **Their handle on the parcel.** Nullable only for the instant between building the
            // row and their response arriving — see `DispatchToNawris`, which writes the row and
            // the code in one transaction and only when a code actually came back. A parcel that
            // exists at the carrier but not here is invisible to every later webhook.
            $table->string('code', 60)->nullable();

            // **Ours, sent as `remote_order_id` and echoed back.** The primary way a webhook finds
            // its parcel. Never changes after creation: changing it detaches the shipment from
            // the record.
            $table->string('reference', 80);

            // Their second identifier, and what is physically scanned at handover. The last
            // fallback when a webhook carries no reference at all — which resends often do not.
            $table->string('bar_code', 60)->nullable();

            // Destination **as sent**, in their vocabulary, frozen. Replayed on every edit.
            $table->string('government', 40);
            $table->string('area', 40)->nullable();

            // What we asked them to collect and remit: the order's remainder less our delivery
            // fee. Two places, like every other money column here.
            $table->decimal('amount_to_collect', 12, 2);

            // Our own delivery fee as taken off at dispatch. Load-bearing: the courier adds
            // *their* fee at the door, so this is what makes the returning figure explicable.
            $table->decimal('delivery_price_deducted', 12, 2)->default(0);

            // What actually came back, raw, including the carrier's own fee. Null until a
            // delivery is reported.
            $table->decimal('collected_amount', 12, 2)->nullable();

            // **Their integer, not just their label.** The status mapping is written against the
            // code; the text is unstable prose that they may reword at any time.
            $table->smallInteger('remote_status_code')->nullable();
            $table->string('remote_status_text')->nullable();

            // Which carrier row this parcel belongs to, so the existing carrier filters keep
            // working. No cascade: a company soft-deletes and the key keeps resolving.
            $table->foreignId('shipping_company_id')->nullable()->constrained('shipping_companies');

            // A flag plus a resolution time is enough — the event log explains the rest. See the
            // delivery-conflict guard: raised on a mismatched delivery, cleared automatically by
            // a clean one or closed by a human.
            $table->timestamp('conflict_raised_at')->nullable();
            $table->timestamp('conflict_resolved_at')->nullable();

            $table->timestamp('dispatched_at')->nullable();

            // Terminal: delivered, returned to us, or written off. What "still out there" reads.
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();
            $table->softDeletes()->index();

            // The webhook's three-tier resolver walks these in order, and the open-parcel guard
            // reads `closed_at`.
            $table->index('bar_code');
            $table->index('closed_at');
        });

        // Partial, like every unique index in this schema: a soft-deleted parcel must not hold
        // its code or its reference hostage. Validation gives the readable 422; these hold under
        // two concurrent dispatches.
        DB::statement(
            'CREATE UNIQUE INDEX nawris_parcels_code_unique ON nawris_parcels (code) WHERE deleted_at IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX nawris_parcels_reference_unique ON nawris_parcels (reference) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('nawris_parcels');
    }
};
