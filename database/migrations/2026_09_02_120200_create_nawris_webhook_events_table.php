<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every status change Nawris has ever told us about, exactly as they said it.
 *
 * **They do not re-send.** Anything not stored when it arrives is gone permanently, so the body
 * is kept verbatim before anything is interpreted — the mapping can be corrected and the history
 * replayed, but only against a payload that was actually written down.
 *
 * **`fingerprint` makes duplicate suppression a database constraint** rather than a status
 * comparison in PHP. Duplicate webhooks are routine, and the comparison approach has a silent
 * failure mode the contract names explicitly: a typed enum compared against a plain string is
 * always unequal, so the guard passes everything while looking like it works. A unique index
 * cannot be silently wrong.
 *
 * **`nawris_parcel_id` is nullable on purpose.** A webhook that matches nothing still gets a row,
 * so it can be found, understood and replayed. The system this was compiled from logged those and
 * dropped them — and «وصل ولم يُعالَج» is the failure mode that otherwise goes unnoticed for
 * weeks, which is why `processed_at` and `error` are here and why something has to watch them.
 *
 * **Neither audited nor soft-deleted**, and this is a decision rather than an oversight. It joins
 * `ActivityLog` in `ModelConventionsTest::NOT_A_BUSINESS_RECORD` for the same reason that one is
 * there: an immutable record of what arrived gains nothing from a second immutable record saying
 * it arrived, and one that could be deleted would not be a log. It is also the only table here
 * that grows with traffic rather than with business, so an `activity_log` row per webhook would
 * double that growth for no reader.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawris_webhook_events', function (Blueprint $table) {
            $table->id();

            // The body, verbatim and authoritative. Every column below is extracted from it for
            // querying; none of them replaces it.
            $table->json('payload');

            // Hash of the meaningful fields. Plain unique, not partial — there are no
            // soft-deleted rows here for an index to have to ignore.
            $table->string('fingerprint', 64)->unique();

            $table->foreignId('nawris_parcel_id')->nullable()->constrained('nawris_parcels')->nullOnDelete();

            // Copied out so an *unmatched* event is searchable by the things support will have in
            // hand — a code off a label, or a reference from our own records.
            $table->string('code', 60)->nullable();
            $table->string('reference', 80)->nullable();

            $table->smallInteger('status_code')->nullable();
            $table->decimal('collected_amount', 12, 2)->nullable();

            $table->timestamp('received_at');

            // Null means the work has not been done. Together with `error` this is the queue
            // somebody has to watch: an event received and never processed is an order that has
            // silently stopped moving.
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index('code');
            $table->index('reference');

            // The two questions the operations screen asks: what has not been processed, and
            // what never matched a parcel.
            $table->index('processed_at');
            $table->index('nawris_parcel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawris_webhook_events');
    }
};
